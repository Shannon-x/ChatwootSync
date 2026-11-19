<?php

namespace Plugin\ChatwootSync\Tests;

use App\Models\Coupon;
use App\Models\Notice;
use App\Models\Order;
use App\Models\Plan;
use App\Models\RechargeOrder;
use App\Models\ServerGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Plugin\ChatwootSync\Services\SupportCommerce;
use Tests\TestCase;

class SupportCommerceTest extends TestCase
{
    use RefreshDatabase;

    private SupportCommerce $commerce;
    private Plan $plan;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['cache.stores.redis' => ['driver' => 'array']]);
        Cache::forgetDriver('redis');
        app()->forgetScopedInstances();
        $this->commerce = new SupportCommerce();
        $group = new ServerGroup();
        $group->name = 'Base';
        $group->save();
        $this->plan = Plan::create([
            'name' => 'Basic', 'group_id' => $group->id, 'show' => true, 'sell' => true,
            'renew' => true, 'capacity_limit' => null, 'transfer_enable' => 50, 'prices' => ['monthly' => 12, 'yearly' => 100],
        ]);
        $this->user = User::create([
            'email' => 'first@example.test', 'password' => 'x',
            'uuid' => (string) Str::uuid(), 'token' => Str::random(32),
        ]);
    }

    public function test_catalog_pages_only_public_sellable_plans_and_marks_full_capacity(): void
    {
        foreach (range(1, 14) as $index) {
            $this->plan->replicate()->forceFill([
                'name' => "Visible $index", 'sort' => $index,
            ])->save();
        }
        $this->plan->replicate()->forceFill(['name' => 'Hidden', 'show' => false])->save();
        $this->plan->replicate()->forceFill(['name' => 'Retired', 'sell' => false])->save();
        $this->plan->update([
            'capacity_limit' => 1,
            'prices' => ['monthly' => 12, 'quarterly' => 0, 'reset_traffic' => 6],
        ]);
        $this->user->update(['plan_id' => $this->plan->id, 'expired_at' => null]);

        $first = $this->commerce->catalog('Visible', 1);
        $second = $this->commerce->catalog('Visible', 2);
        self::assertCount(12, $first['plans']);
        self::assertTrue($first['has_more']);
        self::assertCount(2, $second['plans']);
        self::assertFalse($second['has_more']);
        self::assertEmpty(array_intersect(array_column($first['plans'], 'plan_id'), array_column($second['plans'], 'plan_id')));
        self::assertSame([], $this->commerce->catalog('Hidden')['plans']);
        self::assertSame([], $this->commerce->catalog('Retired')['plans']);
        $basic = $this->commerce->catalog('Basic')['plans'][0];
        self::assertFalse($basic['in_stock']);
        self::assertSame(['monthly'], array_column($basic['prices'], 'period'));
        self::assertSame('12.00', $basic['prices'][0]['price_yuan']);
        self::assertArrayNotHasKey('capacity_limit', $basic);
    }

    public function test_unpaid_first_order_and_newer_balance_recharge_are_visible_without_payment_credentials(): void
    {
        $minute = intdiv(time() - 120, 60) * 60;
        Order::create([
            'user_id' => $this->user->id, 'plan_id' => $this->plan->id, 'trade_no' => 'PANELORDER111111',
            'period' => 'monthly', 'type' => Order::TYPE_NEW_PURCHASE, 'total_amount' => 1200,
            'status' => Order::STATUS_PENDING, 'created_at' => $minute + 8, 'updated_at' => $minute + 8,
        ]);
        $recharge = [
            'user_id' => $this->user->id, 'trade_no' => 'RECHARGE222222', 'deposit_amount' => 2000,
            'pay_amount' => 2200, 'total_credit' => 2000, 'status' => RechargeOrder::STATUS_PENDING,
            'created_at' => $minute + 35, 'updated_at' => $minute + 35,
        ];
        RechargeOrder::create($recharge);
        RechargeOrder::create(['user_id' => $this->user->id + 1, 'trade_no' => 'FOREIGN333333'] + $recharge);

        $rows = $this->commerce->orders($this->user)['orders'];
        self::assertCount(2, $rows);
        self::assertSame(['222222', '111111'], array_column($rows, 'order_reference'));
        self::assertSame('余额充值', $rows[0]['kind']);
        self::assertSame('22.00', $rows[0]['cash_amount_yuan']);
        self::assertSame('待付款', $rows[1]['status']);
        self::assertSame('12.00', $rows[1]['cash_amount_yuan']);
        foreach ($rows as $row) {
            self::assertArrayNotHasKey('trade_no', $row);
            self::assertArrayNotHasKey('payment_url', $row);
            self::assertArrayNotHasKey('user_id', $row);
        }
        self::assertSame([], $this->commerce->orders($this->user, 'FOREIGN333333')['orders']);
        self::assertCount(1, $this->commerce->orders($this->user, 'PANELORDER111111')['orders']);
    }

    public function test_only_current_announcement_codes_are_listed_and_quotes_never_consume_uses(): void
    {
        $hiddenPlan = $this->plan->replicate()->forceFill(['name' => 'HiddenPilotPlan', 'show' => false]);
        $hiddenPlan->save();
        $coupon = Coupon::create([
            'name' => 'Launch', 'code' => 'LAUNCH25', 'show' => true, 'type' => 2, 'value' => 25,
            'started_at' => time() - 600, 'ended_at' => time() + 600,
            'limit_use' => 1, 'limit_use_with_user' => 1, 'limit_period' => ['monthly'],
            'limit_plan_ids' => [$this->plan->id, $hiddenPlan->id],
        ]);
        $coupon->replicate()->forceFill(['code' => 'PRIVATE77'])->save();
        $coupon->replicate()->forceFill(['code' => 'EXPIRED1', 'ended_at' => time() - 60])->save();
        Notice::create(['title' => 'Launch', 'content' => 'LAUNCH25 EXPIRED1', 'show' => true]);
        Notice::create(['title' => 'Draft', 'content' => 'PRIVATE77', 'show' => false]);

        $offers = $this->commerce->offers($this->user);
        self::assertSame(['LAUNCH25'], array_values(array_column($offers['coupons'], 'code')));
        self::assertSame(['Launch'], array_column($offers['notices'], 'title'));
        self::assertStringNotContainsString('HiddenPilotPlan', json_encode($offers, JSON_THROW_ON_ERROR));
        $selection = ['plan_id' => $this->plan->id, 'period' => 'monthly', 'coupon_code' => 'LAUNCH25'];
        $quote = $this->commerce->quote($this->user, $selection);
        self::assertTrue($quote['coupon']['valid']);
        self::assertSame('3.00', $quote['coupon']['discount_yuan']);
        self::assertSame('9.00', $quote['payable_yuan']);
        $wrongPeriod = $this->commerce->quote($this->user, ['period' => 'yearly'] + $selection);
        self::assertFalse($wrongPeriod['coupon']['valid']);
        self::assertSame('100.00', $wrongPeriod['payable_yuan']);
        self::assertSame(0, Order::count());
        self::assertSame(1, (int) $coupon->fresh()->limit_use);

        Order::create([
            'user_id' => $this->user->id, 'plan_id' => $this->plan->id, 'trade_no' => 'USEDCOUPON444444',
            'period' => 'monthly', 'type' => Order::TYPE_NEW_PURCHASE, 'total_amount' => 900,
            'coupon_id' => $coupon->id, 'status' => Order::STATUS_COMPLETED,
        ]);
        self::assertFalse($this->commerce->quote($this->user, $selection)['coupon']['valid']);
        Notice::query()->where('title', 'Launch')->update(['show' => false]);
        self::assertSame([], $this->commerce->offers($this->user)['coupons']);
    }
}
