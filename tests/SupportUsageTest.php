<?php

namespace Plugin\ChatwootSync\Tests;

use App\Models\Order;
use App\Models\PaygLog;
use App\Models\Plan;
use App\Models\StatUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Plugin\ChatwootSync\Services\SupportCommerce;
use Plugin\ChatwootSync\Services\SupportUsage;
use Tests\TestCase;

class SupportUsageTest extends TestCase
{
    use RefreshDatabase;

    private const GB = 1073741824;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.stores.redis' => ['driver' => 'array']]);
        Cache::forgetDriver('redis');
        app()->forgetScopedInstances();
    }

    private function user(array $attributes = []): User
    {
        return User::create($attributes + [
            'email' => Str::random(10) . '@example.test', 'password' => 'unused',
            'uuid' => (string) Str::uuid(), 'token' => Str::random(32),
        ]);
    }

    private function usage(User $user, int $day, float $rate, int $gb, string $recordType = 'd'): void
    {
        StatUser::create([
            'user_id' => $user->id, 'server_rate' => $rate, 'record_at' => $day, 'record_type' => $recordType,
            'u' => 0, 'd' => self::GB * $gb,
        ]);
    }

    public function test_regular_usage_is_scoped_to_owner_calendar_and_rate_even_after_exhaustion(): void
    {
        $month = now()->startOfMonth();
        $last = $month->copy()->subMonth();
        $user = $this->user(['transfer_enable' => 40 * self::GB, 'u' => 10 * self::GB, 'd' => 30 * self::GB]);
        $this->usage($user, $month->timestamp, 3, 6);
        $this->usage($user, $month->timestamp, 10, 20);
        $this->usage($user, $month->timestamp, 2, 100, 'm');
        $this->usage($this->user(), $month->timestamp, 1, 123);
        $this->usage($user, $last->timestamp, 1.5, 3);
        $this->usage($user, $last->copy()->addDay()->timestamp, 1.5, 6);
        $this->usage($user, $month->copy()->subMonths(3)->timestamp, 1, 200);
        $usage = (new SupportUsage())->describe($user->fresh());
        self::assertNull($usage['wallet']);
        self::assertSame(40.0, $usage['traffic_used_gb']);
        self::assertSame(0.0, $usage['traffic_remaining_gb']);
        self::assertSame([$month->format('Y-m'), $last->format('Y-m'), $last->copy()->subMonth()->format('Y-m')], array_column($usage['months'], 'month'));
        [$current, $previous, $empty] = $usage['months'];
        self::assertSame(26.0, $current['billed_gb']);
        self::assertSame(4.0, $current['estimated_actual_gb']);
        self::assertSame([10.0, 3.0], array_column($current['by_rate'], 'multiplier'));
        self::assertSame([20.0, 6.0], array_column($current['by_rate'], 'billed_gb'));
        self::assertSame([2.0, 2.0], array_column($current['by_rate'], 'estimated_actual_gb'));
        self::assertSame([['multiplier' => 1.5, 'billed_gb' => 9.0, 'estimated_actual_gb' => 6.0]], $previous['by_rate']);
        self::assertNull($empty['estimated_actual_gb']);
        self::assertSame([], $empty['by_rate']);
    }

    public function test_bounded_rate_list_preserves_full_billed_total_and_zero_rates_are_unknown(): void
    {
        $user = $this->user();
        $today = now()->startOfDay()->timestamp;
        foreach (range(0, 13) as $rate) {
            $this->usage($user, $today, $rate, $rate);
        }
        $current = (new SupportUsage())->describe($user)['months'][0];
        self::assertCount(12, $current['by_rate']);
        self::assertTrue($current['has_more_rates']);
        self::assertSame(91.0, $current['billed_gb']);
        self::assertNull($current['estimated_actual_gb']);
        self::assertSame(13.0, $current['by_rate'][0]['multiplier']);
    }

    public function test_wallet_uses_native_opening_floor_and_actual_grants_and_does_not_modify_balances(): void
    {
        $month = now()->startOfMonth();
        $last = $month->copy()->subMonth();
        $plan = Plan::create(['name' => 'Wallet', 'group_id' => 1, 'transfer_enable' => 100, 'prices' => ['onetime' => 15]]);
        config(['v2board.payg_plan_ids' => [$plan->id], 'v2board.payg_monthly_floor' => 5]);
        $user = $this->user(['plan_id' => $plan->id, 'expired_at' => null, 'transfer_enable' => 100 * self::GB,
            'u' => self::GB, 'd' => 9 * self::GB, 'payg_auto_topup' => 1]);
        $order = Order::create(['user_id' => $user->id, 'plan_id' => $plan->id, 'trade_no' => 'PRIVATE-ORDER-123456',
            'period' => 'onetime', 'type' => Order::TYPE_NEW_PURCHASE, 'total_amount' => 1500, 'status' => Order::STATUS_COMPLETED]);
        PaygLog::create(['user_id' => $user->id, 'plan_id' => $plan->id, 'order_id' => $order->id,
            'type' => PaygLog::TYPE_RECHARGE, 'opening' => 1, 'bytes' => 100 * self::GB, 'amount' => 1500,
            'bonus_bytes' => 2 * self::GB, 'first_bonus_bytes' => 3 * self::GB,
            'created_at' => $last->copy()->addDays(4)->timestamp]);
        PaygLog::create(['user_id' => $user->id, 'plan_id' => $plan->id, 'type' => PaygLog::TYPE_FLOOR,
            'month' => $last->format('Y-m'), 'amount' => 300, 'bytes' => 20 * self::GB]);
        $this->usage($user, $last->timestamp, 1, 100);
        $this->usage($user, $last->copy()->addDays(6)->timestamp, 3, 9);
        $this->usage($user, $month->timestamp, 10, 20);
        $other = $this->user();
        PaygLog::create(['user_id' => $other->id, 'plan_id' => $plan->id, 'type' => PaygLog::TYPE_RECHARGE, 'first_bonus_bytes' => 99 * self::GB]);
        $original = $user->fresh()->getAttributes();
        $result = (new SupportUsage())->describe($user->fresh());
        $wallet = $result['wallet'];
        self::assertSame(0.15, $wallet['yuan_per_billed_gb']);
        self::assertSame('13.50', $wallet['value_at_current_price_yuan']);
        self::assertSame('5.00', $wallet['monthly_floor_yuan']);
        self::assertTrue($wallet['auto_topup_enabled']);
        self::assertFalse($wallet['has_more_events']);
        self::assertCount(2, $wallet['recent_account_events']);
        [$deduction, $grant] = $wallet['recent_account_events'];
        self::assertSame(20.0, $deduction['floor_deducted_gb']);
        self::assertArrayNotHasKey('purchased_gb', $deduction);
        self::assertSame([100.0, 2.0, 3.0], [$grant['purchased_gb'], $grant['tier_bonus_gb'], $grant['first_bonus_gb']]);
        self::assertSame([20.0, 9.0, 0.0], array_column($result['months'], 'billed_gb'));
        self::assertSame([2.0, 3.0, null], array_column($result['months'], 'estimated_actual_gb'));
        self::assertSame('3.00', $result['months'][1]['floor_deducted_yuan']);
        self::assertTrue($result['months'][1]['settled']);
        self::assertFalse($result['months'][0]['settled']);
        self::assertStringNotContainsString($order->trade_no, json_encode($result));
        self::assertStringNotContainsString($user->email, json_encode($result));
        $ownOrder = (new SupportCommerce())->orders($user, $order->trade_no)['orders'][0];
        self::assertSame(['purchased_gb' => 100.0, 'tier_bonus_gb' => 2.0, 'first_bonus_gb' => 3.0], $ownOrder['payg_delivery']);
        self::assertSame([], (new SupportCommerce())->orders($other, $order->trade_no)['orders']);
        self::assertSame($original, $user->fresh()->getAttributes());
        self::assertSame(3, PaygLog::count());
        self::assertSame(1, Order::count());
    }
}
