<?php

namespace Plugin\ChatwootSync\Tests;

use App\Models\User;
use App\Services\Plugin\PluginConfigService;
use Plugin\ChatwootSync\Services\SupportCommerce;
use Plugin\ChatwootSync\Services\SupportIdentity;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebsiteSupportRoutesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Route::middleware('api')->group(dirname(__DIR__) . '/routes/api.php');
    }

    public function test_tools_start_disabled_and_reject_unknown_paths(): void
    {
        $this->mock(PluginConfigService::class)->shouldReceive('getDbConfig')
            ->once()->with('chatwoot_sync')->andReturn([]);
        $this->getJson('/api/v1/plugin/chatwoot/support/plans')->assertNotFound();
        $this->getJson('/api/v1/plugin/chatwoot/support/tickets')->assertNotFound();
        $this->postJson('/api/v1/plugin/chatwoot/support/orders')->assertStatus(405);
        Http::assertNothingSent();
    }

    public function test_quote_uses_the_verified_customer_and_only_the_requested_options(): void
    {
        $user = new User();
        $this->mock(PluginConfigService::class)->shouldReceive('getDbConfig')->once()->andReturn([]);
        $this->mock(SupportIdentity::class)->shouldReceive('authorize')->once()
            ->with(\Mockery::type(\Illuminate\Http\Request::class), [], true)->andReturn($user);
        $this->mock(SupportCommerce::class)->shouldReceive('quote')->once()
            ->with($user, ['plan_id' => '3', 'period' => 'monthly', 'addon_group_ids' => [4, 5]])
            ->andReturn(['payable_yuan' => '10.00']);

        $response = $this->getJson('/api/v1/plugin/chatwoot/support/quote?plan_id=3&period=monthly&addon_group_ids=4,5&user_id=77&coupon_code=');
        $response->assertOk()->assertJsonPath('source', 'XBoard')->assertJsonPath('data.payable_yuan', '10.00');
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        Http::assertNothingSent();
    }
}
