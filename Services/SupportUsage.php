<?php

namespace Plugin\ChatwootSync\Services;

use App\Models\StatUser;
use App\Models\User;
use App\Services\Mcp\McpPresenter as P;
use App\Services\Payg\PaygConfig;
use App\Services\Payg\PaygService;

class SupportUsage
{
    private const RATE_LIMIT = 12;
    private const EVENT_LIMIT = 8;

    public function describe(User $user): array
    {
        $config = PaygConfig::fromSettings();
        $plan = $user->plan;
        $result = [
            'plan' => $plan?->name,
            'traffic_total_gb' => P::gb($user->transfer_enable),
            'traffic_used_gb' => P::gb($user->getTotalUsedTraffic()),
            'traffic_remaining_gb' => P::gb($user->getRemainingTraffic()),
            'last_reset_at' => P::time($user->last_reset_at),
            'next_reset_at' => P::time($user->next_reset_at),
            'note' => '当前配额计数以面板重置周期为准。以下按自然月、使用当时的倍率汇总，日统计可能延迟。实际流量由计费流量除以各自倍率推算，同倍率节点合并统计；零倍率用量无法反推。',
        ];
        if (!$plan || !PaygService::isWallet($user, $config) || PaygConfig::unitPrice($plan) === null) {
            $result['wallet'] = null;
            $result['months'] = $this->accountMonths($user);
            return $result;
        }

        $ledger = app(PaygService::class)->ledger($user, $config);
        $unit = (float) $ledger['unit_price'];
        $result['wallet'] = [
            'value_at_current_price_yuan' => P::yuan(PaygConfig::centsFor($unit, $user->getRemainingTraffic())),
            'yuan_per_billed_gb' => round($unit / 100, 6),
            'monthly_floor_yuan' => P::yuan($ledger['monthly_floor']),
            'auto_topup_enabled' => $config->autoTopupEnable && (bool) $user->payg_auto_topup,
            'recent_account_events' => array_map([$this, 'walletEvent'], array_slice($ledger['entries'], 0, self::EVENT_LIMIT)),
            'has_more_events' => count($ledger['entries']) > self::EVENT_LIMIT,
            'note' => '折合金额按当前单价计算。现钱包从最近开通当天起按日汇总；月内有计费用量且不足保底时，月结补扣差额，最多扣至剩余配额为零。近期事件取自本人的钱包历史。',
        ];
        $result['months'] = [];
        foreach ($ledger['months'] as $month) {
            $result['months'][] = $this->month($month['month'], $month['by_rate']) + [
                'usage_value_at_current_price_yuan' => P::yuan($month['used']),
                'floor_deducted_yuan' => P::yuan($month['floor_charged']),
                'floor_deducted_gb' => P::gb($month['floor_bytes']),
                'settled' => $month['settled'],
            ];
        }
        return $result;
    }

    private function accountMonths(User $user): array
    {
        $now = now();
        $start = $now->copy()->startOfMonth();
        $months = [];
        for ($i = 0; $i < PaygService::LEDGER_MONTHS; $i++) {
            $end = min($now->timestamp + 1, $start->copy()->addMonth()->timestamp);
            $byRate = StatUser::query()->where('user_id', $user->id)->where('record_type', 'd')
                ->where('record_at', '>=', $start->timestamp)->where('record_at', '<', $end)
                ->groupBy('server_rate')->selectRaw('server_rate AS rate, SUM(u + d) AS bytes')
                ->get()->toArray();
            $months[] = $this->month($start->format('Y-m'), $byRate);
            $start->subMonth();
        }
        return $months;
    }

    private function month(string $month, array $byRate): array
    {
        $billed = 0;
        $actual = 0.0;
        $canEstimate = $byRate !== [];
        $lines = [];
        usort($byRate, static fn ($a, $b) => $b['bytes'] <=> $a['bytes'] ?: $a['rate'] <=> $b['rate']);
        foreach ($byRate as $group) {
            $bytes = (int) $group['bytes'];
            $rate = (float) $group['rate'];
            $billed += $bytes;
            if ($rate > 0) {
                $actual += $bytes / $rate;
            } else {
                $canEstimate = false;
            }
            if (count($lines) < self::RATE_LIMIT) {
                $lines[] = [
                    'multiplier' => $rate,
                    'billed_gb' => P::gb($bytes),
                    'estimated_actual_gb' => $rate > 0 ? P::gb($bytes / $rate) : null,
                ];
            }
        }
        return [
            'month' => $month,
            'billed_gb' => P::gb($billed),
            'estimated_actual_gb' => $canEstimate ? P::gb($actual) : null,
            'by_rate' => $lines,
            'has_more_rates' => count($byRate) > self::RATE_LIMIT,
        ];
    }

    private function walletEvent(array $event): array
    {
        $row = ['type' => $event['type'], 'at' => P::time($event['at']), 'amount_yuan' => P::yuan($event['amount'])];
        if ($event['type'] === 'floor') {
            return $row + ['floor_deducted_gb' => P::gb($event['bytes'])];
        }
        return $row + [
            'purchased_gb' => P::gb($event['bytes']),
            'tier_bonus_gb' => P::gb($event['bonus_bytes']),
            'first_bonus_gb' => P::gb($event['first_bonus_bytes']),
        ];
    }
}
