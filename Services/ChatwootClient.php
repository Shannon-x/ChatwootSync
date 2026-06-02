<?php

namespace Plugin\ChatwootSync\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Chatwoot HTTP API 客户端
 *
 * Docs: https://www.chatwoot.com/developers/api
 *
 * 认证：所有请求带 Header `api_access_token`
 */
class ChatwootClient
{
    public function __construct(
        private string $baseUrl,
        private string $accountId,
        private string $apiToken,
        private int $timeoutSec = 15,
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    /**
     * 按 email 在 Chatwoot 找 contact
     * 返回第一个 email 完全匹配（case-insensitive）的 contact，否则 null
     */
    public function findContactByEmail(string $email): ?array
    {
        $email = strtolower(trim($email));
        if ($email === '') {
            return null;
        }

        $resp = $this->request()
            ->get("{$this->baseUrl}/api/v1/accounts/{$this->accountId}/contacts/search", [
                'q' => $email,
                'include' => 'contact_inboxes',
                'page' => 1,
            ]);

        if (!$resp->successful()) {
            $this->logFailure('findContactByEmail', $resp, ['email' => $email]);
            return null;
        }

        $payload = $resp->json('payload', []) ?: [];
        foreach ($payload as $c) {
            if (strtolower((string) ($c['email'] ?? '')) === $email) {
                return $c;
            }
        }
        return null;
    }

    /**
     * 更新 contact 自定义属性
     * PUT 行为：Chatwoot 对 custom_attributes 是 MERGE，不会清除其他键
     */
    public function updateContact(int $contactId, array $customAttributes): bool
    {
        $resp = $this->request()
            ->put("{$this->baseUrl}/api/v1/accounts/{$this->accountId}/contacts/{$contactId}", [
                'custom_attributes' => $customAttributes,
            ]);

        if (!$resp->successful()) {
            $this->logFailure('updateContact', $resp, [
                'contact_id' => $contactId,
                'keys' => array_keys($customAttributes),
            ]);
            return false;
        }
        return true;
    }

    /**
     * 分页列出 contact（回填用）
     * 返回 ['payload' => array, 'meta' => array]
     *
     * Chatwoot v4 的 GET /contacts 端点固定每页约 15 条（不接受 page_size 参数）
     */
    public function listContacts(int $page = 1, ?string $sort = '-last_activity_at'): array
    {
        $resp = $this->request()
            ->get("{$this->baseUrl}/api/v1/accounts/{$this->accountId}/contacts", [
                'page' => $page,
                'sort' => $sort,
            ]);

        if (!$resp->successful()) {
            $this->logFailure('listContacts', $resp, ['page' => $page]);
            return ['payload' => [], 'meta' => []];
        }

        return [
            'payload' => $resp->json('payload', []) ?: [],
            'meta' => $resp->json('meta', []) ?: [],
        ];
    }

    private function request(): PendingRequest
    {
        return Http::withHeaders([
            'api_access_token' => $this->apiToken,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ])->timeout($this->timeoutSec)->retry(2, 200, throw: false);
    }

    private function logFailure(string $op, $resp, array $ctx = []): void
    {
        Log::warning("[ChatwootSync] {$op} HTTP " . $resp->status(), array_merge($ctx, [
            'body' => substr((string) $resp->body(), 0, 500),
        ]));
    }
}
