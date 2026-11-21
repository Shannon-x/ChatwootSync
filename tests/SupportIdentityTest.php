<?php

namespace Plugin\ChatwootSync\Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Plugin\ChatwootSync\Services\SupportIdentity;
use Plugin\ChatwootSync\Services\WebsitePanelSession;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

class SupportIdentityTest extends TestCase
{
    use RefreshDatabase;

    private const SERVICE_TOKEN = 'fake-website-service-secret-for-tests-only';
    private const CHAT_URL = 'https://chat.example.test/api/v1/accounts/2/conversations/31';
    private const CONFIG = [
        'enable_support_tools' => true,
        'support_bearer_token' => self::SERVICE_TOKEN,
        'chatwoot_account_id' => 2,
        'support_assistant_id' => 5,
        'support_website_inbox_id' => 7,
        'chatwoot_base_url' => 'https://chat.example.test',
        'chatwoot_api_token' => 'test-client-secret',
    ];
    private const HEADERS = [
        'Authorization' => 'Bearer ' . self::SERVICE_TOKEN,
        'X-Chatwoot-Account-Id' => '2',
        'X-Chatwoot-Assistant-Id' => '5',
        'X-Chatwoot-Conversation-Id' => '971',
        'X-Chatwoot-Conversation-Display-Id' => '31',
        'X-Chatwoot-Contact-Id' => '19',
    ];
    private const CONVERSATION = [
        'id' => 31, 'account_id' => 2, 'inbox_id' => 7,
        'meta' => ['channel' => 'Channel::WebWidget', 'sender' => ['id' => 19, 'email' => null]],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function request(array $headers = [], array $parameters = []): Request
    {
        $request = Request::create('/support', 'GET', $parameters);
        $request->headers->replace(array_replace(self::HEADERS, $headers));
        return $request;
    }

    private function assertDenied(int $status, Request $request, bool $personal = true, array $config = []): void
    {
        try {
            (new SupportIdentity())->authorize($request, array_replace(self::CONFIG, $config), $personal);
            self::fail('The website support request was unexpectedly authorized.');
        } catch (HttpExceptionInterface $error) {
            self::assertSame($status, $error->getStatusCode());
        }
    }

    public function test_anonymous_website_session_can_read_public_catalog_only(): void
    {
        Http::fake([self::CHAT_URL . '*' => Http::response(self::CONVERSATION)]);
        $request = $this->request();
        self::assertNull((new SupportIdentity())->authorize($request, self::CONFIG, false));
        $this->assertDenied(403, $request);
        Http::assertSentCount(2);
        Http::assertSent(fn (ClientRequest $request) => $request->url() === self::CHAT_URL . '?include_latest_widget_message_proof=1');
        Http::assertSent(fn (ClientRequest $request) =>
            $request->url() === self::CHAT_URL && $request->hasHeader('api_access_token', 'test-client-secret'));
    }

    #[DataProvider('deniedRequests')]
    public function test_invalid_admission_never_contacts_chatwoot(array $headers, array $config, int $status): void
    {
        $this->assertDenied($status, $this->request($headers), false, $config);
        Http::assertNothingSent();
    }

    public static function deniedRequests(): array
    {
        return [
            'disabled' => [[], ['enable_support_tools' => false], 404],
            'wrong service' => [['Authorization' => 'Bearer wrong'], [], 401],
            'short configured key' => [['Authorization' => 'Bearer test'], ['support_bearer_token' => 'test'], 401],
            'other account' => [['X-Chatwoot-Account-Id' => '8'], [], 403],
            'other assistant' => [['X-Chatwoot-Assistant-Id' => '6'], [], 403],
            'no conversation' => [['X-Chatwoot-Conversation-Display-Id' => ''], [], 403],
            'no contact' => [['X-Chatwoot-Contact-Id' => ''], [], 403],
        ];
    }

    #[DataProvider('conflictingConversations')]
    public function test_real_conversation_must_match_the_caller(array $changes): void
    {
        Http::fake([self::CHAT_URL . '*' => Http::response(array_replace_recursive(self::CONVERSATION, $changes))]);
        $this->assertDenied(403, $this->request(), false);
    }

    public static function conflictingConversations(): array
    {
        return [
            'wrong display ID' => [['id' => 971]],
            'wrong account' => [['account_id' => 8]],
            'wrong inbox' => [['inbox_id' => 8]],
            'wrong channel' => [['meta' => ['channel' => 'Channel::Telegram']]],
            'contact changed' => [['meta' => ['sender' => ['id' => 20]]]],
        ];
    }

    public function test_upstream_failure_never_grants_access(): void
    {
        Http::fake([self::CHAT_URL . '*' => Http::response([], 500)]);
        $this->assertDenied(503, $this->request(), false);
    }

    #[DataProvider('untrustedMessages')]
    public function test_private_data_requires_a_server_record_of_the_exact_incoming_message(?array $overrides, bool $activeSession = true): void
    {
        config(['cache.stores.redis' => ['driver' => 'array']]);
        Cache::forgetDriver('redis');
        $source = (string) Str::uuid();
        $digest = hash('sha256', $source);
        $email = 'customer@example.test';
        if ($activeSession) {
            $user = User::create(['email' => $email, 'password' => 'unused', 'uuid' => (string) Str::uuid(), 'token' => Str::random(32)]);
            $user->withAccessToken($user->createToken('panel', ['user'])->accessToken);
            app(WebsitePanelSession::class)->bind($user, $source, $email, 7);
        }
        $proof = $overrides === null ? null : array_replace([
            'id' => 87, 'conversation_id' => 31, 'contact_id' => 19, 'source_digest' => $digest,
        ], $overrides);
        $legacy = ['id' => 87, 'message_type' => 0, 'conversation_id' => 31, 'sender_type' => 'Contact', 'sender_id' => 19,
            'additional_attributes' => ['widget_sender_source_digest' => $digest]];
        $conversation = self::CONVERSATION;
        $conversation['meta']['sender']['identifier'] = $email;
        Http::fake([self::CHAT_URL . '?include_latest_widget_message_proof=1' => Http::response($conversation + [
            'messages' => [$legacy], 'last_non_activity_message' => $legacy, 'latest_widget_message_proof' => $proof,
        ])]);
        $this->assertDenied(403, $this->request([
            'X-Chatwoot-Responding-To-Message-Id' => '87',
            'X-Chatwoot-Verified-Email' => 'customer@example.test',
        ]));
    }

    public static function untrustedMessages(): array
    {
        return [
            'other message' => [['id' => 88]],
            'other contact' => [['contact_id' => 20]],
            'different conversation' => [['conversation_id' => 1]],
            'unsigned latest turn despite a signed legacy message' => [null],
            'empty proof' => [['source_digest' => '']],
            'malformed proof' => [['source_digest' => 'invalid']],
            'no live panel session' => [[], false],
        ];
    }
}
