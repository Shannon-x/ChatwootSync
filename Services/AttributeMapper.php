<?php

namespace Plugin\ChatwootSync\Services;

use App\Models\User;

/** Contact attributes shown to staff. Authenticated customer queries use separate, conversation-scoped tools. */
class AttributeMapper
{
    private const BYTES_PER_GB = 1073741824; // 1024^3
    private const TEXT_EMPTY = '';   // Chatwoot link/text 字段清空用
    private const NUM_EMPTY = 0;     // Chatwoot number 字段清空用

    public static function map(?User $user, string $xboardBaseUrl, ?string $forceStatus = null): array
    {
        $today = date('Y-m-d');

        // 无 Xboard 账号：所有业务字段重置为空，保留 status + synced_at
        if (!$user) {
            return self::emptyAttrs($forceStatus ?? 'no_account', $today);
        }

        // ----- 流量 -----
        $usedBytes = (int) $user->u + (int) $user->d;
        $totalBytes = (int) $user->transfer_enable;
        $usedGb = round($usedBytes / self::BYTES_PER_GB, 2);
        $totalGb = $totalBytes > 0 ? round($totalBytes / self::BYTES_PER_GB, 2) : 0.0;
        $usedPct = $totalBytes > 0 ? round(($usedBytes / $totalBytes) * 100, 1) : 0.0;

        // ----- 到期 -----
        $expiredAtTs = (int) $user->expired_at;
        $expiredAt = $expiredAtTs > 0 ? date('Y-m-d', $expiredAtTs) : self::TEXT_EMPTY;

        // ----- 状态判定 -----
        $status = 'active';
        if ($user->banned) {
            $status = 'banned';
        } elseif ($expiredAtTs > 0 && $expiredAtTs < time()) {
            $status = 'expired';
        }

        // ----- 余额/佣金（Xboard 内部以"分"存储，÷100 转元）-----
        $balance = round(((int) $user->balance) / 100, 2);
        $commissionBalance = round(((int) $user->commission_balance) / 100, 2);

        // ----- 最近登录 -----
        $lastLoginAt = !empty($user->last_login_at)
            ? date('Y-m-d', (int) $user->last_login_at)
            : self::TEXT_EMPTY;

        // ----- 套餐名 -----
        $planName = $user->plan ? (string) $user->plan->name : '无套餐';

        return [
            'xboard_plan' => $planName,
            'xboard_status' => $forceStatus ?? $status,
            'xboard_expired_at' => $expiredAt,
            'xboard_balance' => $balance,
            'xboard_commission_balance' => $commissionBalance,
            'xboard_used_percent' => $usedPct,
            'xboard_used_gb' => $usedGb,
            'xboard_total_gb' => $totalGb,
            // Chatwoot merges attributes. Clear the historical value on the next sync.
            'xboard_inviter_email' => self::TEXT_EMPTY,
            'xboard_last_login_at' => $lastLoginAt,
            'xboard_synced_at' => $today,
        ];
    }

    /**
     * Xboard 找不到对应用户时的空白属性集（保留状态 + 同步时间）
     */
    private static function emptyAttrs(string $status, string $today): array
    {
        return [
            'xboard_plan' => self::TEXT_EMPTY,
            'xboard_status' => $status,
            'xboard_expired_at' => self::TEXT_EMPTY,
            'xboard_balance' => self::NUM_EMPTY,
            'xboard_commission_balance' => self::NUM_EMPTY,
            'xboard_used_percent' => self::NUM_EMPTY,
            'xboard_used_gb' => self::NUM_EMPTY,
            'xboard_total_gb' => self::NUM_EMPTY,
            'xboard_inviter_email' => self::TEXT_EMPTY,
            'xboard_last_login_at' => self::TEXT_EMPTY,
            'xboard_synced_at' => $today,
        ];
    }
}
