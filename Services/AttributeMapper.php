<?php

namespace Plugin\ChatwootSync\Services;

use App\Models\User;
use App\Utils\Helper;

/**
 * Xboard User → Chatwoot custom_attributes 字段映射
 *
 * 字段对应 docs/CUSTOM_ATTRIBUTES.md（13 个字段）
 *
 * ⚠️ Chatwoot 的 contact PATCH 是对 custom_attributes JSONB 做 **MERGE**
 *    而不是 replace。如果某次同步把字段值给出为 null/缺失，Chatwoot
 *    会保留旧值。所以我们必须**总是输出全部 13 个 key**，对没有的字段
 *    用 sentinel 值（'' 给文本/link，0 给数字）显式覆盖，否则用户从
 *    "有套餐"变成"无套餐"等场景永远刷不掉旧数据。
 */
class AttributeMapper
{
    private const BYTES_PER_GB = 1073741824; // 1024^3
    private const TEXT_EMPTY = '';   // Chatwoot link/text 字段清空用
    private const NUM_EMPTY = 0;     // Chatwoot number 字段清空用

    /**
     * 把一个 Xboard User 映射成 Chatwoot custom_attributes
     *
     * @param  User|null   $user           null 表示对应 Xboard 用户不存在
     * @param  string      $xboardBaseUrl  Xboard 公网地址（拼 admin_url）
     * @param  string|null $forceStatus    覆盖 xboard_status（如 'no_account'）
     * @return array<string, mixed>
     */
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

        // ----- 链接 -----
        $base = rtrim($xboardBaseUrl, '/');
        $adminUrl = $base . '/#/user?id=' . $user->id;

        $subscribeUrl = self::TEXT_EMPTY;
        if (!empty($user->token)) {
            try {
                $subscribeUrl = (string) Helper::getSubscribeUrl((string) $user->token);
            } catch (\Throwable $e) {
                $subscribeUrl = self::TEXT_EMPTY;
            }
        }

        // ----- 最近登录 -----
        $lastLoginAt = !empty($user->last_login_at)
            ? date('Y-m-d', (int) $user->last_login_at)
            : self::TEXT_EMPTY;

        // ----- 邀请人邮箱（不依赖 relationLoaded，因为我们调用方总是 with()）-----
        $inviterEmail = $user->invite_user
            ? (string) $user->invite_user->email
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
            'xboard_inviter_email' => $inviterEmail,
            'xboard_admin_url' => $adminUrl,
            'xboard_subscribe_url' => $subscribeUrl,
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
            'xboard_admin_url' => self::TEXT_EMPTY,
            'xboard_subscribe_url' => self::TEXT_EMPTY,
            'xboard_last_login_at' => self::TEXT_EMPTY,
            'xboard_synced_at' => $today,
        ];
    }
}
