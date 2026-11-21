<?php

namespace Plugin\ChatwootSync\Services;

use App\Models\User;
use Illuminate\Http\Request;

class SupportIdentity
{
    public function authorize(Request $request, array $config, bool $requireCustomer): ?User
    {
        abort_unless(in_array($config['enable_support_tools'] ?? false, [true, 1, '1'], true), 404);

        $secret = (string) ($config['support_bearer_token'] ?? '');
        abort_unless(strlen($secret) >= 32 && hash_equals($secret, (string) $request->bearerToken()), 401);
        $accountId = (string) ($config['chatwoot_account_id'] ?? '');
        $assistantId = (string) ($config['support_assistant_id'] ?? '');
        $inboxId = (string) ($config['support_website_inbox_id'] ?? '');
        abort_unless(ctype_digit($accountId) && ctype_digit($assistantId) && ctype_digit($inboxId), 503);
        abort_unless($request->header('X-Chatwoot-Account-Id') === $accountId
            && $request->header('X-Chatwoot-Assistant-Id') === $assistantId, 403);

        $displayId = (string) $request->header('X-Chatwoot-Conversation-Display-Id');
        $contactId = (string) $request->header('X-Chatwoot-Contact-Id');
        abort_unless(ctype_digit($displayId) && (int) $displayId > 0 && ctype_digit($contactId) && (int) $contactId > 0, 403);

        $baseUrl = (string) ($config['chatwoot_base_url'] ?? '');
        $apiToken = (string) ($config['chatwoot_api_token'] ?? '');
        abort_unless($baseUrl !== '' && $apiToken !== '', 503);
        $conversation = (new ChatwootClient($baseUrl, $accountId, $apiToken))->getConversation((int) $displayId, $requireCustomer);
        abort_unless($conversation && (string) ($conversation['id'] ?? '') === $displayId
            && (string) ($conversation['account_id'] ?? '') === $accountId
            && (string) ($conversation['inbox_id'] ?? '') === $inboxId
            && ($conversation['meta']['channel'] ?? '') === 'Channel::WebWidget'
            && (string) ($conversation['meta']['sender']['id'] ?? '') === $contactId, 403);

        if (!$requireCustomer) {
            return null;
        }

        $messageId = (string) $request->header('X-Chatwoot-Responding-To-Message-Id');
        $proof = $conversation['latest_widget_message_proof'] ?? [];
        abort_unless(ctype_digit($messageId) && (string) ($proof['id'] ?? '') === $messageId
            && (string) ($proof['conversation_id'] ?? '') === $displayId
            && (string) ($proof['contact_id'] ?? '') === $contactId, 403, 'Open support from your signed-in panel.');

        $identifier = (string) ($conversation['meta']['sender']['identifier'] ?? '');
        $digest = (string) ($proof['source_digest'] ?? '');
        $user = app(WebsitePanelSession::class)->resolve($digest, $identifier, (int) $inboxId);
        abort_unless($user, 403, 'Open support from your signed-in panel.');
        return $user;
    }
}
