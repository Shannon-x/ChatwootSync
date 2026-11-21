<?php

namespace Plugin\ChatwootSync\Services;

use App\Models\Order;
use App\Models\PaygLog;
use App\Models\Plan;
use App\Models\RechargeOrder;
use App\Models\User;
use App\Services\Mcp\McpOffers;
use App\Services\Mcp\McpOrderSpec;
use App\Services\Mcp\McpPresenter as P;
use App\Services\OrderService;
use App\Services\PlanCapacity;
use App\Services\PlanCustomizationService;
use App\Services\PlanService;

class SupportCommerce
{
    public function catalog(?string $keyword = null, int $page = 1): array
    {
        $query = Plan::where('show', true)->where('sell', true)->orderBy('sort')->orderBy('id');
        if ($keyword !== null && trim($keyword) !== '') {
            $query->where('name', 'like', '%' . trim($keyword) . '%');
        }
        $page = $query->simplePaginate(12, ['*'], 'page', $page);
        $plans = $page->getCollection();
        $remaining = PlanCapacity::remaining($plans);
        $customizer = app(PlanCustomizationService::class);
        $items = [];
        foreach ($plans as $plan) {
            $prices = [];
            foreach ((array) $plan->prices as $period => $price) {
                if (is_numeric($price) && (float) $price > 0 && Plan::isValidPeriod($period)
                    && !in_array($period, [Plan::PERIOD_RESET_TRAFFIC, Plan::PERIOD_TRAFFIC_TOPUP], true)) {
                    $prices[] = ['period' => $period, 'period_name' => P::periodName($period), 'price_yuan' => number_format((float) $price, 2, '.', '')];
                }
            }
            $lines = [];
            foreach ($customizer->addonGroupsForDisplay($plan) as $id => $line) {
                $lines[] = ['group_id' => (int) $id, 'name' => $line['name'], 'mode' => $line['mode'], 'monthly_fee_yuan' => P::yuan($line['price'])];
            }
            $items[] = [
                'plan_id' => (int) $plan->id, 'name' => $plan->name, 'prices' => $prices,
                'traffic_gb' => (int) $plan->transfer_enable, 'device_limit' => $plan->device_limit,
                'speed_limit_mbps' => $plan->speed_limit, 'lines' => $lines,
                'in_stock' => ($remaining[(int) $plan->id] ?? null) !== 0, 'description' => P::plain($plan->content, 250),
            ];
        }
        return ['plans' => $items, 'has_more' => $page->hasMorePages()];
    }

    public function orders(User $user, ?string $tradeNo = null): array
    {
        $orders = Order::where('user_id', $user->id);
        $recharges = RechargeOrder::where('user_id', $user->id);
        if ($tradeNo !== null && $tradeNo !== '') {
            $orders->where('trade_no', $tradeNo);
            $recharges->where('trade_no', $tradeNo);
        }
        $orders = $orders->latest('created_at')->orderByDesc('id')->limit(8)
            ->get(['id', 'trade_no', 'plan_id', 'type', 'period', 'total_amount', 'balance_amount', 'status', 'created_at']);
        $deliveredOrders = $orders->whereIn('status', [Order::STATUS_COMPLETED, Order::STATUS_DISCOUNTED])->pluck('id');
        $deliveries = PaygLog::where('user_id', $user->id)->whereIn('order_id', $deliveredOrders)
            ->whereIn('type', [PaygLog::TYPE_RECHARGE, PaygLog::TYPE_AUTO_TOPUP])
            ->get(['order_id', 'bytes', 'bonus_bytes', 'first_bonus_bytes'])->keyBy('order_id');
        $names = Plan::whereIn('id', $orders->pluck('plan_id')->unique())->pluck('name', 'id');
        $rows = $orders->map(fn (Order $order) => [
            'order_reference' => strlen((string) $order->trade_no) > 6 ? substr($order->trade_no, -6) : null, 'kind' => P::orderType((int) $order->type),
            'plan' => $names[$order->plan_id] ?? null, 'period' => P::periodName((string) $order->period),
            'cash_amount_yuan' => P::yuan($order->total_amount), 'balance_used_yuan' => P::yuan($order->balance_amount),
            'status' => P::orderStatus((int) $order->status), 'created_at' => (int) $order->created_at,
        ] + ($deliveries->has($order->id) ? ['payg_delivery' => [
            'purchased_gb' => P::gb($deliveries[$order->id]->bytes),
            'tier_bonus_gb' => P::gb($deliveries[$order->id]->bonus_bytes),
            'first_bonus_gb' => P::gb($deliveries[$order->id]->first_bonus_bytes),
        ]] : []));
        $credits = $recharges->latest('created_at')->orderByDesc('id')->limit(8)
            ->get(['trade_no', 'status', 'pay_amount', 'deposit_amount', 'bonus_amount', 'handling_amount', 'total_credit', 'created_at'])
            ->map(fn (RechargeOrder $order) => [
                'order_reference' => strlen((string) $order->trade_no) > 6 ? substr($order->trade_no, -6) : null, 'kind' => '余额充值', 'cash_amount_yuan' => P::yuan($order->pay_amount),
                'deposit_yuan' => P::yuan($order->deposit_amount), 'bonus_credit_yuan' => P::yuan($order->bonus_amount),
                'handling_fee_yuan' => P::yuan($order->handling_amount), 'credit_yuan' => P::yuan($order->total_credit), 'created_at' => (int) $order->created_at,
                'status' => RechargeOrder::$statusMap[(int) $order->status] ?? '未知状态',
            ]);
        $recent = $rows->concat($credits)->sortByDesc('created_at')->take(8)->values()->map(function (array $row) {
            $row['created_at'] = P::time($row['created_at']);
            return $row;
        });
        return ['orders' => $recent->all()];
    }

    public function offers(User $user): array
    {
        $offers = McpOffers::view($user);
        foreach ($offers['coupons'] as &$coupon) {
            if (is_array($coupon['plans'])) {
                $coupon['plans'] = '限指定套餐，请按所选套餐试算。';
            }
        }
        unset($coupon);
        return $offers;
    }

    public function quote(User $user, array $options): array
    {
        $plan = Plan::where('show', true)->where('sell', true)->find($options['plan_id']);
        abort_unless($plan, 422, '请选择目录中的在售套餐。');
        $spec = McpOrderSpec::resolve($user, ['mode' => 'purchase'] + $options);
        (new PlanService($plan))->validatePurchase($user, $spec['period']);
        $quote = app(PlanCustomizationService::class)->quote($plan, $spec['period'], $spec['options'], $user);
        $preview = OrderService::previewOrderType($user, $plan, $spec['period'], $quote);
        $payable = (int) $preview['payable'];
        $coupon = null;
        if (isset($options['coupon_code']) && trim($options['coupon_code']) !== '') {
            $discount = McpOffers::estimate($options['coupon_code'], $user, $plan, $spec['period'], $payable);
            $coupon = ['valid' => $discount['message'] === null, 'discount_yuan' => P::yuan($discount['discount']), 'message' => $discount['message']];
            $payable = max(0, $payable - $discount['discount']);
        }
        return [
            'plan_id' => (int) $plan->id, 'plan_name' => $plan->name, 'period' => $spec['period'],
            'price_yuan' => P::yuan($quote['amount']), 'remaining_value_deducted_yuan' => P::yuan($preview['surplus_amount']),
            'coupon' => $coupon, 'payable_yuan' => P::yuan($payable),
            'cash_due_estimate_yuan' => P::yuan(max(0, $payable - (int) $user->balance)), 'blocked_reason' => $preview['blocked'],
            'note' => '仅作试算。会员及首单折扣以面板最终结算为准。',
        ];
    }
}
