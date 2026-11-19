<?php

namespace Plugin\ChatwootSync\Services;

use App\Models\Server;
use App\Models\User;
use App\Services\EmailVerification;
use App\Utils\CacheKey;
use Illuminate\Support\Facades\Cache;

class SupportConnection
{
    private const LIMIT = 32;

    public function diagnose(User $user, ?string $node = null): array
    {
        $groups = $user->effectiveGroupIds();
        $now = time();
        $remaining = $user->getRemainingTraffic();
        $status = match (true) {
            (bool) $user->banned => 'account_blocked',
            $user->expired_at !== null && (int) $user->expired_at <= $now => 'expired',
            (int) $user->transfer_enable <= 0 => 'no_traffic_allocation',
            $remaining === 0 => 'traffic_exhausted',
            EmailVerification::blocksSubscribe($user) => 'panel_email_verification_required',
            $groups === [] => 'no_line_entitlement',
            default => 'eligible',
        };
        $result = [
            'account_status' => $status,
            'plan' => $user->plan?->name,
            'addon_lines' => array_values(array_map(static fn (array $line) => [
                'name' => $line['name'], 'source' => $line['source'],
            ], app(\App\Services\PlanCustomizationService::class)->addonGroupsForUser($user->plan, $user))),
            'expires_at' => $user->expired_at ? date(DATE_ATOM, (int) $user->expired_at) : null,
            'traffic_total_gb' => round((int) $user->transfer_enable / 1073741824, 2),
            'traffic_remaining_gb' => round($remaining / 1073741824, 2),
            'nodes' => [],
            'has_more' => false,
            'note' => '仅列本人可见节点。客户端协议和插件可能进一步筛选线路。状态来自最近心跳与上报，实际可达性需在客户端测试。',
        ];
        if ($status !== 'eligible') {
            return $result;
        }

        $query = Server::query()->where('show', true)->where(function ($query) use ($groups) {
            foreach ($groups as $id) {
                $query->orWhereJsonContains('group_ids', (string) $id);
            }
        });
        if ($node !== null && trim($node) !== '') {
            $query->where('name', 'like', '%' . trim($node) . '%');
        }
        $servers = $query->orderBy('sort')->orderBy('id')->limit(self::LIMIT + 1)
            ->get(['id', 'parent_id', 'name', 'type', 'rate', 'rate_time_enable', 'rate_time_ranges']);
        $result['has_more'] = $servers->count() > self::LIMIT;
        $servers = $servers->take(self::LIMIT);
        $keys = [];
        foreach ($servers as $server) {
            $prefix = 'SERVER_' . strtoupper($server->type);
            $keyId = $server->parent_id ?: $server->id;
            $keys[$server->id] = [CacheKey::get($prefix . '_LAST_CHECK_AT', $keyId), CacheKey::get($prefix . '_LAST_PUSH_AT', $keyId)];
        }
        $values = $keys ? Cache::many(array_unique(array_merge(...array_values($keys)))) : [];
        foreach ($servers as $server) {
            [$checkKey, $pushKey] = $keys[$server->id];
            $check = (int) ($values[$checkKey] ?? 0);
            $push = (int) ($values[$pushKey] ?? 0);
            $result['nodes'][] = [
                'name' => $server->name,
                'protocol' => $server->type,
                'traffic_multiplier' => $server->getCurrentRate(),
                'report_status' => match (true) {
                    $check === 0 && $push === 0 => 'no_recent_data',
                    $check <= $now - Server::CHECK_INTERVAL => 'heartbeat_stale',
                    $push <= $now - Server::CHECK_INTERVAL => 'heartbeat_only',
                    default => 'reporting',
                },
                'last_check_at' => $check ? date(DATE_ATOM, $check) : null,
                'last_push_at' => $push ? date(DATE_ATOM, $push) : null,
            ];
        }
        return $result;
    }
}
