<?php

namespace Plugin\ChatwootSync\Jobs;

use App\Models\User;
use App\Services\Plugin\PluginConfigService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Plugin\ChatwootSync\Services\AttributeMapper;
use Plugin\ChatwootSync\Services\ChatwootClient;

/**
 * 异步把 Xboard User 信息推送到 Chatwoot Contact 的 custom_attributes
 *
 * 使用方式：
 *   PushToChatwootJob::dispatch(userId: 123);
 *   PushToChatwootJob::dispatch(userId: 123, contactId: 456);
 *   PushToChatwootJob::dispatch(userId: null, contactId: 456, forceStatus: 'no_account');
 *
 * 注：使用 default queue，不指定 onQueue() —— 这样标准 `php artisan queue:work`
 *     即可处理，免去管理员配置专用 worker。
 */
class PushToChatwootJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** 最多重试 3 次，间隔 30s */
    public int $tries = 3;
    public int $backoff = 30;

    public function __construct(
        public ?int $userId,
        public ?int $contactId = null,
        public ?string $forceStatus = null,
    ) {}

    public function handle(): void
    {
        $cfg = app(PluginConfigService::class)->getDbConfig('chatwoot_sync');

        $baseUrl = (string) ($cfg['chatwoot_base_url'] ?? '');
        $apiToken = (string) ($cfg['chatwoot_api_token'] ?? '');
        $accountId = (string) ($cfg['chatwoot_account_id'] ?? '');

        if ($baseUrl === '' || $apiToken === '' || $accountId === '') {
            Log::warning('[ChatwootSync] Job aborted: chatwoot config incomplete');
            return;
        }

        $client = new ChatwootClient($baseUrl, $accountId, $apiToken);

        // ----- 1. 拿到 Xboard User（可能为空，表示 no_account 状态）-----
        $user = null;
        if ($this->userId) {
            $user = User::with(['plan', 'invite_user'])->find($this->userId);
        }

        // ----- 2. 解析 contact_id（若 webhook 没提供，则按 email 反查）-----
        $contactId = $this->contactId;
        if (!$contactId && $user && !empty($user->email)) {
            $contact = $client->findContactByEmail((string) $user->email);
            if (!$contact) {
                Log::info('[ChatwootSync] no Chatwoot contact for email', ['email' => $user->email]);
                return;
            }
            $contactId = (int) $contact['id'];
        }

        if (!$contactId) {
            Log::warning('[ChatwootSync] Job aborted: cannot determine contact_id', [
                'user_id' => $this->userId,
            ]);
            return;
        }

        // ----- 3. 计算 custom_attributes -----
        $xboardBaseUrl = (string) (admin_setting('app_url') ?: url('/'));
        $attrs = AttributeMapper::map($user, $xboardBaseUrl, $this->forceStatus);

        // ----- 4. 推送 -----
        $ok = $client->updateContact($contactId, $attrs);

        if (!$ok) {
            // 让 Job 重试（抛异常触发 backoff）
            throw new \RuntimeException("Chatwoot updateContact failed: contact_id={$contactId}");
        }

        Log::info('[ChatwootSync] synced', [
            'user_id' => $this->userId,
            'contact_id' => $contactId,
            'status' => $attrs['xboard_status'] ?? null,
        ]);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('[ChatwootSync] job permanently failed', [
            'user_id' => $this->userId,
            'contact_id' => $this->contactId,
            'error' => $e->getMessage(),
        ]);
    }
}
