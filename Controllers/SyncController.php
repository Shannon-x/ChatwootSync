<?php

namespace Plugin\ChatwootSync\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Plugin\PluginConfigService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Plugin\ChatwootSync\Jobs\PushToChatwootJob;

class SyncController extends Controller
{
    /**
     * 接收 Chatwoot 推过来的 webhook
     *
     * Expected URL:
     *   POST /api/v1/plugin/chatwoot/sync?token=<webhook_secret>
     *
     * Expected events: contact_created, contact_updated
     */
    public function handle(Request $request): JsonResponse
    {
        $config = $this->config();
        $expected = (string) ($config['webhook_secret'] ?? '');

        if ($expected === '') {
            return response()->json(['ok' => false, 'error' => 'webhook_secret_not_configured'], 503);
        }

        $token = (string) $request->query('token', '');
        if (!hash_equals($expected, $token)) {
            Log::warning('[ChatwootSync] webhook unauthorized', [
                'ip' => $request->ip(),
                'event' => $request->input('event'),
            ]);
            return response()->json(['ok' => false, 'error' => 'forbidden'], 403);
        }

        $event = (string) $request->input('event', '');
        if (!in_array($event, ['contact_created', 'contact_updated'], true)) {
            return response()->json(['ok' => true, 'skipped' => true]);
        }

        // 校验 account_id 匹配——拒绝其他 Chatwoot 账号误推过来的 webhook
        $configuredAccountId = (int) ($config['chatwoot_account_id'] ?? 0);
        $incomingAccountId = (int) ($request->input('account.id') ?? $request->input('account_id') ?? 0);
        if ($configuredAccountId > 0 && $incomingAccountId > 0 && $configuredAccountId !== $incomingAccountId) {
            Log::warning('[ChatwootSync] webhook account_id mismatch', [
                'configured' => $configuredAccountId,
                'incoming' => $incomingAccountId,
            ]);
            return response()->json(['ok' => false, 'error' => 'account_mismatch'], 400);
        }

        $contactId = (int) $request->input('id', 0);
        if ($contactId <= 0) {
            return response()->json(['ok' => false, 'error' => 'invalid_contact_id'], 400);
        }

        // 优先 email 匹配，其次 telegram_id
        $user = $this->resolveUser($request, $config);

        if (!$user) {
            // 没找到对应 Xboard 用户：仍然写入 no_account 状态，让客服知道
            PushToChatwootJob::dispatch(
                userId: null,
                contactId: $contactId,
                forceStatus: 'no_account'
            );
            return response()->json(['ok' => true, 'matched' => false]);
        }

        PushToChatwootJob::dispatch(
            userId: $user->id,
            contactId: $contactId
        );

        return response()->json(['ok' => true, 'matched' => true, 'xboard_user_id' => $user->id]);
    }

    /**
     * 健康检查：仅返回配置完整度（不泄露具体值）
     */
    public function health(): JsonResponse
    {
        $config = $this->config();
        $hasWidget = !empty($config['enable_widget']);
        $hasWidgetToken = !empty($config['widget_token']);
        $hasHmac = !empty($config['hmac_secret']);

        return response()->json([
            'ok' => true,
            'config' => [
                'chatwoot_base_url' => !empty($config['chatwoot_base_url']),
                'chatwoot_account_id' => !empty($config['chatwoot_account_id']),
                'chatwoot_api_token' => !empty($config['chatwoot_api_token']),
                'widget_token' => $hasWidgetToken,
                'hmac_secret' => $hasHmac,
                'webhook_secret' => !empty($config['webhook_secret']),
                'enable_widget' => $hasWidget,
                'enable_push_sync' => (bool) ($config['enable_push_sync'] ?? true),
                'enable_telegram_id_match' => (bool) ($config['enable_telegram_id_match'] ?? true),
            ],
            'derived' => [
                // Widget 真正可用（带身份验证）的标志位
                'widget_identity_ready' => $hasWidget && $hasWidgetToken && $hasHmac,
            ],
        ]);
    }

    /**
     * 按 email / telegram_id 解析对应 Xboard 用户
     */
    private function resolveUser(Request $request, array $config): ?User
    {
        $email = strtolower(trim((string) $request->input('email', '')));
        if ($email !== '') {
            $user = User::byEmail($email)->first();
            if ($user) {
                return $user;
            }
        }

        if (!empty($config['enable_telegram_id_match'])) {
            $tgId = $request->input('additional_attributes.social_profiles.telegram');
            // Chatwoot 也可能把 telegram_id 放在 identifier 字段
            $tgId = $tgId ?: $request->input('identifier');
            if ($tgId !== null && $tgId !== '') {
                $user = User::where('telegram_id', $tgId)->first();
                if ($user) {
                    return $user;
                }
            }
        }

        return null;
    }

    private function config(): array
    {
        return app(PluginConfigService::class)->getDbConfig('chatwoot_sync');
    }
}
