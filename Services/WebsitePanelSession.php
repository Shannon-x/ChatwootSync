<?php

namespace Plugin\ChatwootSync\Services;

use App\Models\User;
use App\Services\AuthService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;

final class WebsitePanelSession
{
    private const TTL_SECONDS = 600;

    public static function validSource(string $source): bool
    {
        return preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/', $source) === 1;
    }

    public function panelUser(Request $request): ?User
    {
        if (!$request->bearerToken()) {
            return null;
        }
        // Resolve this particular request with Sanctum, even when a previous call populated the guard.
        $guard = clone Auth::guard('sanctum');
        $user = $guard->setRequest($request)->forgetUser()->user();
        if (!$user instanceof User || $user->banned || !self::isPanelToken($user->currentAccessToken())) {
            return null;
        }
        return $user;
    }

    public function bind(User $user, string $source, string $identifier, int $inboxId): void
    {
        abort_unless(self::validSource($source) && $inboxId > 0, 422);
        $token = $user->currentAccessToken();
        abort_unless(!$user->banned && self::isPanelToken($token) && self::matchesIdentifier($user, $identifier), 403);

        $key = self::key(hash('sha256', $source), $identifier, $inboxId);
        $cache = Cache::store('redis');
        $cache->lock($key . ':lock', 5)->block(2, static function () use ($cache, $key, $user, $token) {
            $cache->put($key, ['user_id' => (int) $user->id, 'token_id' => (int) $token->id], self::TTL_SECONDS);
        });
    }

    public function revoke(User $user, string $source, string $identifier, int $inboxId): void
    {
        abort_unless(self::validSource($source) && $inboxId > 0, 422);
        $token = $user->currentAccessToken();
        abort_unless(self::isPanelToken($token), 403);

        $key = self::key(hash('sha256', $source), $identifier, $inboxId);
        $cache = Cache::store('redis');
        $cache->lock($key . ':lock', 5)->block(2, static function () use ($cache, $key, $user, $token) {
            $binding = $cache->get($key);
            if (($binding['user_id'] ?? null) === (int) $user->id && ($binding['token_id'] ?? null) === (int) $token->id) {
                $cache->forget($key);
            }
        });
    }

    public function resolve(string $sourceDigest, string $identifier, int $inboxId): ?User
    {
        if (!preg_match('/\A[0-9a-f]{64}\z/', $sourceDigest) || $identifier === '' || $inboxId < 1) {
            return null;
        }
        $binding = Cache::store('redis')->get(self::key($sourceDigest, $identifier, $inboxId));
        if (!$binding) {
            return null;
        }
        $user = User::find($binding['user_id']);
        if (!$user || $user->banned || !self::matchesIdentifier($user, $identifier)) {
            return null;
        }
        // The indexed relation rechecks this exact device session and its owner on every private lookup.
        $token = $user->tokens()->find($binding['token_id']);
        if (!self::isPanelToken($token)) {
            return null;
        }
        $expiration = config('sanctum.expiration');
        $valid = (!$token->expires_at || !$token->expires_at->isPast())
            && (!$expiration || $token->created_at->gt(now()->subMinutes($expiration)));
        $callback = Sanctum::$accessTokenAuthenticationCallback;
        if (!$valid || (is_callable($callback) && !$callback($token, $valid))) {
            return null;
        }
        return $user;
    }

    private static function isPanelToken(mixed $token): bool
    {
        return $token instanceof PersonalAccessToken
            && ($token->abilities === [AuthService::ABILITY_USER] || $token->abilities === ['*']);
    }

    private static function matchesIdentifier(User $user, string $identifier): bool
    {
        return $identifier !== '' && (strcasecmp((string) $user->email, $identifier) === 0
            || hash_equals((string) $user->uuid, $identifier));
    }

    private static function key(string $sourceDigest, string $identifier, int $inboxId): string
    {
        return 'chatwoot_sync:website_session:' . $inboxId . ':' . $sourceDigest . ':' . hash('sha256', strtolower($identifier));
    }
}
