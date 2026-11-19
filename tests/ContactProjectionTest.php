<?php

namespace Plugin\ChatwootSync\Tests;

use App\Models\User;
use Plugin\ChatwootSync\Services\AttributeMapper;
use Tests\TestCase;

class ContactProjectionTest extends TestCase
{
    public function test_refresh_clears_the_legacy_inviter_email_and_never_returns_subscription_credentials(): void
    {
        $user = new User();
        $user->token = 'test-credential';
        $user->setRelation('plan', null);
        $user->setRelation('invite_user', (new User())->forceFill(['email' => 'inviter@example.test']));

        $attributes = AttributeMapper::map($user, 'https://example.test');

        self::assertSame('', $attributes['xboard_inviter_email']);
        self::assertArrayNotHasKey('xboard_subscribe_url', $attributes);
        self::assertArrayNotHasKey('xboard_admin_url', $attributes);
        self::assertStringNotContainsString('test-credential', json_encode($attributes));
    }
}
