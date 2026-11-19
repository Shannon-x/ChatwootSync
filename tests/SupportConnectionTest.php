<?php

namespace Plugin\ChatwootSync\Tests;

use App\Models\Plan;
use App\Models\Server;
use App\Models\ServerGroup;
use App\Models\User;
use App\Utils\CacheKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Plugin\ChatwootSync\Services\SupportConnection;
use Tests\TestCase;

class SupportConnectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.stores.redis' => ['driver' => 'array']]);
        Cache::forgetDriver('redis');
        app()->forgetScopedInstances();
    }

    private function user(array $attributes = []): User
    {
        return new User($attributes + [
            'group_id' => 1, 'transfer_enable' => 1073741824, 'u' => 0, 'd' => 0,
        ]);
    }

    private function node(string $name, int $group = 1, array $attributes = []): Server
    {
        return Server::create($attributes + [
            'name' => $name, 'show' => true, 'group_ids' => [(string) $group],
            'type' => 'trojan', 'rate' => 2, 'host' => 'private.example.test',
            'port' => 443, 'server_port' => 443, 'cert_config' => ['tls_key' => 'test-secret'],
        ]);
    }

    public function test_traffic_exhaustion_and_expiry_stop_node_disclosure(): void
    {
        $this->node('Entitled node');
        $service = new SupportConnection();
        $result = $service->diagnose($this->user(['d' => 1073741824]));
        self::assertSame('traffic_exhausted', $result['account_status']);
        self::assertSame([], $result['nodes']);
        self::assertEquals(0, $result['traffic_remaining_gb']);

        $result = $service->diagnose($this->user(['expired_at' => time() - 1]));
        self::assertSame('expired', $result['account_status']);
        self::assertSame([], $result['nodes']);
    }

    public function test_nodes_are_scoped_to_effective_groups_and_only_return_panel_fields(): void
    {
        $parent = $this->node('Internal parent', 9, ['show' => false]);
        $this->node('Hong Kong base', 1, ['parent_id' => $parent->id, 'sort' => 1]);
        $this->node('Hong Kong purchased', 2, ['sort' => 2]);
        $this->node('Hong Kong manual', 3, ['sort' => 3]);
        $this->node('Hong Kong other', 4);
        $this->node('Hong Kong hidden', 1, ['show' => false]);
        $this->node('Tokyo', 1);

        $sample = time() - 20;
        Cache::put(CacheKey::get('SERVER_TROJAN_LAST_CHECK_AT', $parent->id), $sample);
        $result = (new SupportConnection())->diagnose($this->user([
            'plan_options' => ['addon_groups' => [2]], 'admin_group_ids' => [3],
        ]), 'Hong Kong');

        self::assertSame('eligible', $result['account_status']);
        self::assertFalse($result['has_more']);
        self::assertSame(['Hong Kong base', 'Hong Kong purchased', 'Hong Kong manual'], array_column($result['nodes'], 'name'));
        $first = $result['nodes'][0];
        self::assertSame('heartbeat_only', $first['report_status']);
        self::assertSame(date(DATE_ATOM, $sample), $first['last_check_at']);
        self::assertNull($first['last_push_at']);
        self::assertSame('no_recent_data', $result['nodes'][1]['report_status']);
        self::assertSame(['name', 'protocol', 'traffic_multiplier', 'report_status', 'last_check_at', 'last_push_at'], array_keys($first));
        self::assertStringNotContainsString('private.example.test', json_encode($result));
        self::assertStringNotContainsString('test-secret', json_encode($result));

        Cache::put(CacheKey::get('SERVER_TROJAN_LAST_PUSH_AT', $parent->id), $sample);
        self::assertSame('reporting', (new SupportConnection())->diagnose($this->user(), 'base')['nodes'][0]['report_status']);
        Cache::put(CacheKey::get('SERVER_TROJAN_LAST_CHECK_AT', $parent->id), time() - Server::CHECK_INTERVAL);
        self::assertSame('heartbeat_stale', (new SupportConnection())->diagnose($this->user(), 'base')['nodes'][0]['report_status']);
    }

    public function test_plan_included_and_purchased_lines_use_the_customer_facing_labels(): void
    {
        $included = ServerGroup::forceCreate(['name' => 'Internal label']);
        $purchased = ServerGroup::forceCreate(['name' => 'Purchased']);
        $unowned = ServerGroup::forceCreate(['name' => 'Unpurchased']);
        $plan = Plan::create([
            'name' => 'Home plan', 'group_id' => $included->id, 'transfer_enable' => 10,
            'show' => true, 'sell' => true, 'renew' => true, 'prices' => ['monthly' => 20],
            'customization' => ['addon_groups' => [
                $included->id => ['mode' => 'included', 'label' => 'Included line'],
                $purchased->id => ['mode' => 'optional', 'price' => 100],
                $unowned->id => ['mode' => 'optional', 'price' => 100],
            ]],
        ]);
        $this->node('Included line node', $included->id);
        $this->node('Purchased node', $purchased->id);
        $this->node('Not purchased', $unowned->id);
        $customer = $this->user([
            'plan_id' => $plan->id, 'group_id' => null,
            'plan_options' => ['addon_groups' => [$purchased->id]],
        ]);
        $result = (new SupportConnection())->diagnose($customer);

        self::assertSame('Home plan', $result['plan']);
        self::assertSame([
            ['name' => 'Included line', 'source' => 'included'],
            ['name' => 'Purchased', 'source' => 'purchased'],
        ], $result['addon_lines']);
        self::assertSame(['Included line node', 'Purchased node'], array_column($result['nodes'], 'name'));
    }

    public function test_only_subscription_email_restriction_blocks_diagnosed_nodes(): void
    {
        $this->node('Service');
        $user = $this->user(['email_verify_started_at' => time() - 16 * 86400]);
        $service = new SupportConnection();
        config(['v2board.email_verify_restrict_mode' => 'subscribe']);
        $blocked = $service->diagnose($user);
        self::assertSame('panel_email_verification_required', $blocked['account_status']);
        self::assertSame([], $blocked['nodes']);

        config(['v2board.email_verify_restrict_mode' => 'features']);
        self::assertSame('eligible', $service->diagnose($user)['account_status']);
    }

    public function test_missing_group_and_limited_results_are_reported_independently(): void
    {
        $service = new SupportConnection();
        self::assertSame('no_line_entitlement', $service->diagnose($this->user(['group_id' => null]))['account_status']);
        for ($number = 1; $number <= 33; $number++) {
            $this->node("Line {$number}", 1, ['sort' => $number]);
        }

        $result = $service->diagnose($this->user());
        self::assertTrue($result['has_more']);
        self::assertCount(32, $result['nodes']);
        self::assertSame('Line 32', $result['nodes'][31]['name']);
        $missing = $service->diagnose($this->user(), 'Absent');
        self::assertSame([], $missing['nodes']);
        self::assertFalse($missing['has_more']);
    }
}
