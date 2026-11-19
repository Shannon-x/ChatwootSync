<?php

namespace Plugin\ChatwootSync\Tests;

use App\Models\Order;
use App\Models\Plan;
use App\Models\ServerGroup;
use App\Models\User;
use App\Services\Plugin\PluginConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Plugin\ChatwootSync\Services\WebsitePanelSession;
use Tests\TestCase;

class WebsiteCustomerFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_website_customer_can_read_the_catalog_and_only_their_own_orders(): void
    {
        config(['cache.stores.redis' => ['driver' => 'array']]);
        Cache::forgetDriver('redis');
        app()->forgetScopedInstances();
        Route::middleware('api')->group(dirname(__DIR__) . '/routes/api.php');
        Http::preventStrayRequests();
        $secret = Str::random(48);
        $this->mock(PluginConfigService::class)->shouldReceive('getDbConfig')->andReturn([
            'enable_support_tools' => true, 'support_bearer_token' => $secret, 'chatwoot_account_id' => '1',
            'support_assistant_id' => '2', 'support_website_inbox_id' => '3',
            'chatwoot_base_url' => 'https://support.example.test', 'chatwoot_api_token' => 'test-agent',
        ]);
        $source = (string) Str::uuid();
        Http::fake(['https://support.example.test/api/v1/accounts/1/conversations/44' => Http::response([
            'id' => 44, 'account_id' => 1, 'inbox_id' => 3,
            'meta' => ['channel' => 'Channel::WebWidget', 'sender' => ['id' => 18, 'email' => 'one@example.test', 'identifier' => 'one@example.test']],
            'messages' => [['id' => 71, 'conversation_id' => 44, 'message_type' => 0, 'sender_type' => 'Contact', 'sender_id' => 18,
                'additional_attributes' => ['widget_sender_source_digest' => hash('sha256', $source)]]],
        ])]);
        $group = new ServerGroup();
        $group->name = 'Public line';
        $group->save();
        $plan = Plan::create(['name' => 'Basic', 'group_id' => $group->id, 'show' => true, 'sell' => true,
            'renew' => true, 'capacity_limit' => null, 'transfer_enable' => 25, 'prices' => ['monthly' => 12]]);
        foreach (['one', 'two'] as $account) {
            $user = User::create(['email' => "$account@example.test", 'password' => 'unusable', 'email_verified_at' => time(),
                'uuid' => Str::uuid()->toString(), 'token' => Str::random(32)]);
            Order::create(['user_id' => $user->id, 'plan_id' => $plan->id, 'period' => 'monthly', 'type' => Order::TYPE_NEW_PURCHASE,
                'trade_no' => 'receipt-' . $account, 'total_amount' => 1200, 'status' => Order::STATUS_COMPLETED]);
        }
        $headers = ['Authorization' => "Bearer $secret", 'X-Chatwoot-Account-Id' => '1', 'X-Chatwoot-Assistant-Id' => '2',
            'X-Chatwoot-Contact-Id' => '18', 'X-Chatwoot-Conversation-Display-Id' => '44'];
        $base = '/api/v1/plugin/chatwoot/support';
        $this->getJson("$base/plans", $headers)->assertOk()->assertJsonPath('data.plans.0.prices.0.price_yuan', '12.00');
        $this->getJson("$base/orders", $headers)->assertForbidden();
        $headers['X-Chatwoot-Responding-To-Message-Id'] = '71';
        $owner = User::findByExactEmail('one@example.test');
        $token = $owner->createToken('panel', ['user'])->accessToken;
        $owner->withAccessToken($token);
        app(WebsitePanelSession::class)->bind($owner, $source, $owner->email, 3);
        $this->getJson("$base/orders", $headers)->assertOk()->assertJsonCount(1, 'data.orders')->assertJsonPath('data.orders.0.order_reference', 'pt-one')->assertJsonMissingPath('data.orders.0.trade_no');
        $this->getJson("$base/orders?trade_no=receipt-two", $headers)->assertOk()->assertJsonCount(0, 'data.orders');
        $this->getJson("$base/quote?period=monthly&plan_id=$plan->id", $headers)->assertOk()->assertJsonPath('data.price_yuan', '12.00');
        $token->delete();
        $this->getJson("$base/orders", $headers)->assertForbidden();
        self::assertSame(2, Order::count());
    }
}
