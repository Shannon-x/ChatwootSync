<?php

namespace Plugin\ChatwootSync\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Plugin\PluginConfigService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Plugin\ChatwootSync\Services\SupportCommerce;
use Plugin\ChatwootSync\Services\SupportConnection;
use Plugin\ChatwootSync\Services\SupportIdentity;

class WebsiteSupportController extends Controller
{
    public function show(Request $request, string $topic): JsonResponse
    {
        $config = app(PluginConfigService::class)->getDbConfig('chatwoot_sync');
        $user = app(SupportIdentity::class)->authorize($request, $config, $topic !== 'plans');
        $commerce = app(SupportCommerce::class);

        $data = match ($topic) {
            'plans' => $this->plans($request, $commerce),
            'orders' => $commerce->orders($user, $request->validate([
                'trade_no' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/D'],
            ])['trade_no'] ?? null),
            'connection' => app(SupportConnection::class)->diagnose($user, $request->validate([
                'node' => ['nullable', 'string', 'max:80'],
            ])['node'] ?? null),
            'offers' => $commerce->offers($user),
            'quote' => $commerce->quote($user, $this->quoteSelection($request)),
        };

        return response()->json([
            'source' => 'XBoard',
            'checked_at' => now()->toIso8601String(),
            'data' => $data,
        ])->header('Cache-Control', 'private, no-store');
    }

    private function plans(Request $request, SupportCommerce $commerce): array
    {
        $filters = $request->validate([
            'keyword' => ['nullable', 'string', 'max:80'],
            'page' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);

        return $commerce->catalog($filters['keyword'] ?? null, (int) ($filters['page'] ?? 1));
    }

    private function quoteSelection(Request $request): array
    {
        $selection = $request->validate([
            'plan_id' => ['required', 'integer', 'min:1'],
            'period' => ['required', 'string', 'max:32'],
            'coupon_code' => ['nullable', 'string', 'max:64'],
            'traffic_gb' => ['nullable', 'integer', 'min:1'],
            'addon_group_ids' => ['nullable', 'string', 'regex:/^[1-9][0-9]{0,8}(,[1-9][0-9]{0,8}){0,15}$/D'],
        ]);

        if (isset($selection['addon_group_ids'])) {
            $selection['addon_group_ids'] = array_map('intval', explode(',', $selection['addon_group_ids']));
        }

        return array_filter($selection, static fn ($value) => $value !== null);
    }
}
