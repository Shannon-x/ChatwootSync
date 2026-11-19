<?php

namespace Plugin\ChatwootSync\Tests;

use App\Models\User;
use App\Services\Plugin\PluginConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Plugin\ChatwootSync\Services\WebsitePanelSession;
use Tests\TestCase;

class WebsiteWidgetBindingTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_the_current_panel_session_can_attach_and_release_its_browser(): void
    {
        config(['cache.stores.redis' => ['driver' => 'array']]);
        Cache::forgetDriver('redis');
        Route::middleware('api')->group(dirname(__DIR__) . '/routes/api.php');
        $this->mock(PluginConfigService::class)->shouldReceive('getDbConfig')->andReturn([
            'enable_widget' => true, 'support_website_inbox_id' => '3', 'hmac_secret' => 'widget-test-secret',
        ]);
        $user = User::create(['email' => 'panel@example.test', 'password' => 'unusable',
            'uuid' => (string) Str::uuid(), 'token' => Str::random(32)]);
        $source = (string) Str::uuid();
        $url = '/api/v1/plugin/chatwoot/widget-identity';
        $sessions = app(WebsitePanelSession::class);
        $headers = ['Authorization' => 'Bearer ' . $user->createToken('panel', ['user'])->plainTextToken, 'X-Chatwoot-Widget-Source' => $source];
        $this->getJson($url . '?email=another@example.test', $headers)->assertOk()
            ->assertJsonPath('identifier', $user->email)->assertJsonPath('identifier_hash', hash_hmac('sha256', $user->email, 'widget-test-secret'));
        self::assertTrue($sessions->resolve(hash('sha256', $source), $user->email, 3)->is($user));
        $this->deleteJson($url, [], $headers)->assertOk()->assertJsonPath('detached', true);
        self::assertNull($sessions->resolve(hash('sha256', $source), $user->email, 3));

        $user->tokens()->delete();
        $this->getJson($url, $headers)->assertOk()->assertJsonPath('authenticated', false)->assertJsonMissingPath('identifier_hash');
        self::assertNull($sessions->resolve(hash('sha256', $source), $user->email, 3));
    }
}
