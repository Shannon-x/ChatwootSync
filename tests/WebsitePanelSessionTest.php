<?php

namespace Plugin\ChatwootSync\Tests;

use App\Models\User;
use App\Services\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Plugin\ChatwootSync\Services\WebsitePanelSession;
use Tests\TestCase;

class WebsitePanelSessionTest extends TestCase
{
    use RefreshDatabase;

    private WebsitePanelSession $sessions;
    private User $user;
    private string $source;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.stores.redis' => ['driver' => 'array']]);
        Cache::forgetDriver('redis');
        $this->sessions = new WebsitePanelSession();
        $this->source = (string) Str::uuid();
        $this->user = User::create([
            'email' => 'panel@example.test', 'password' => 'unused',
            'uuid' => (string) Str::uuid(), 'token' => Str::random(32),
        ]);
    }

    private function authenticate(string $bearer): ?User
    {
        $request = Request::create('/widget-identity', 'GET', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $bearer]);
        return $this->sessions->panelUser($request);
    }

    public function test_authentication_requires_a_live_customer_bearer_and_does_not_reuse_another_request(): void
    {
        $auth = new AuthService($this->user);
        $issued = $auth->generateAuthData();
        $authenticated = $this->authenticate(substr($issued['auth_data'], 7));
        self::assertSame($this->user->id, $authenticated?->id);
        self::assertNull($this->authenticate('invalid'));
        self::assertNull($this->sessions->panelUser(Request::create('/widget-identity')));
        self::assertNull($this->authenticate(substr($auth->generateAdminAuthData()['auth_data'], 7)));
        self::assertNull($this->authenticate($auth->generateApiToken('probe', true)['token']));
        foreach ([['mcp:read'], ['unexpected'], ['user', 'admin:read']] as $abilities) {
            $token = $this->user->createToken('not-panel', $abilities);
            self::assertNull($this->authenticate($token->plainTextToken));
        }
        $expired = $this->user->createToken('expired', ['user'], now()->subMinute());
        self::assertNull($this->authenticate($expired->plainTextToken));
        $legacy = $this->user->createToken('legacy', ['*']);
        self::assertSame($this->user->id, $this->authenticate($legacy->plainTextToken)?->id);
        $this->user->update(['banned' => 1]);
        self::assertNull($this->authenticate($legacy->plainTextToken));
    }

    public function test_logout_is_device_scoped_and_a_delayed_revoke_keeps_a_new_binding(): void
    {
        $sourceB = (string) Str::uuid();
        $email = $this->user->email;
        $deviceA = $this->authenticate($this->user->createToken('device-a', ['user'])->plainTextToken);
        $deviceB = $this->authenticate($this->user->createToken('device-b', ['user'])->plainTextToken);
        $this->sessions->bind($deviceA, $this->source, $email, 4);
        $this->sessions->bind($deviceB, $sourceB, $email, 4);
        $hashA = hash('sha256', $this->source);
        $hashB = hash('sha256', $sourceB);
        self::assertSame($this->user->id, $this->sessions->resolve($hashA, $email, 4)?->id);
        self::assertNull($this->sessions->resolve($hashA, $email, 5));
        self::assertNull($this->sessions->resolve($this->source, $email, 4));
        self::assertNull($this->sessions->resolve($hashA, 'other@example.test', 4));

        (new AuthService($this->user))->removeSession((string) $deviceA->currentAccessToken()->id);
        self::assertNull($this->sessions->resolve($hashA, $email, 4));
        self::assertSame($this->user->id, $this->sessions->resolve($hashB, $email, 4)?->id);
        $this->sessions->bind($deviceB, $this->source, $email, 4);
        $this->sessions->revoke($deviceA, $this->source, $email, 4);
        self::assertSame($this->user->id, $this->sessions->resolve($hashA, $email, 4)?->id);
        $this->sessions->revoke($deviceB, $this->source, $email, 4);
        self::assertNull($this->sessions->resolve($hashA, $email, 4));
    }

    public function test_bindings_expire_and_each_tool_call_rechecks_token_abilities_expiry_and_ban(): void
    {
        $email = $this->user->email;
        $login = $this->user->createToken('web', ['user']);
        $device = $this->authenticate($login->plainTextToken);
        $this->sessions->bind($device, $this->source, $email, 1);
        $source = hash('sha256', $this->source);

        $login->accessToken->forceFill(['abilities' => ['mcp:read']])->save();
        self::assertNull($this->sessions->resolve($source, $email, 1));
        $login->accessToken->forceFill(['abilities' => ['user'], 'expires_at' => now()->subMinute()])->save();
        self::assertNull($this->sessions->resolve($source, $email, 1));
        $login->accessToken->forceFill(['expires_at' => null])->save();
        $this->user->update(['banned' => 1]);
        self::assertNull($this->sessions->resolve($source, $email, 1));
        $this->user->update(['banned' => 0, 'email' => 'changed@example.test']);
        self::assertNull($this->sessions->resolve($source, $email, 1));
        $this->user->update(['email' => $email]);
        config(['sanctum.expiration' => 2]);
        $this->travel(3)->minutes();
        self::assertNull($this->sessions->resolve($source, $email, 1));
        config(['sanctum.expiration' => null]);
        self::assertSame($this->user->id, $this->sessions->resolve($source, $email, 1)?->id);
        $this->travel(8)->minutes();
        self::assertNull($this->sessions->resolve($source, $email, 1));
    }
}
