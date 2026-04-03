<?php

declare(strict_types=1);

namespace OpenClaw\Tests\Discord;

use OpenClaw\Discord\DiscordController;
use OpenClaw\Tests\AbstractTestCase;

/**
 * Unit tests for Discord\DiscordController helper logic.
 *
 * These tests target the pure logic around auth, parsing, message shaping,
 * and button/session helpers without requiring a full WordPress runtime.
 *
 * @covers \OpenClaw\Discord\DiscordController
 */
class DiscordControllerTest extends AbstractTestCase {

    protected function setUp(): void {
        parent::setUp();

        $GLOBALS['wpoc_deleted_transients'] = [];
        $GLOBALS['wpoc_mock_users'] = [];
    }

    /** @test */
    public function extract_command_payload_returns_run_action_and_prompt_from_subcommand(): void {
        $result = $this->invokePrivate('extractCommandPayload', [[[
            'type'    => 1,
            'name'    => 'run',
            'options' => [
                ['name' => 'prompt', 'value' => 'Show me site info'],
            ],
        ]]]);

        $this->assertSame(
            ['action' => 'run', 'prompt' => 'Show me site info'],
            $result
        );
    }

    /** @test */
    public function extract_command_payload_returns_reset_action_for_reset_subcommand(): void {
        $result = $this->invokePrivate('extractCommandPayload', [[[
            'type' => 1,
            'name' => 'reset',
        ]]]);

        $this->assertSame(['action' => 'reset', 'prompt' => ''], $result);
    }

    /** @test */
    public function extract_command_payload_supports_flat_prompt_options(): void {
        $result = $this->invokePrivate('extractCommandPayload', [[[
            'name'  => 'prompt',
            'value' => 'Flat option prompt',
        ]]]);

        $this->assertSame(
            ['action' => 'run', 'prompt' => 'Flat option prompt'],
            $result
        );
    }

    /** @test */
    public function extract_user_id_prefers_member_user_then_user_then_unknown(): void {
        $memberId = $this->invokePrivate('extractUserId', [[
            'member' => ['user' => ['id' => '111']],
            'user'   => ['id' => '222'],
        ]]);
        $userId = $this->invokePrivate('extractUserId', [[
            'user' => ['id' => '222'],
        ]]);
        $unknownId = $this->invokePrivate('extractUserId', [[]]);

        $this->assertSame('111', $memberId);
        $this->assertSame('222', $userId);
        $this->assertSame('unknown', $unknownId);
    }

    /** @test */
    public function allowed_channel_and_user_checks_require_exact_matches(): void {
        $settings = [
            'discord_allowed_channel_ids' => ' 123,456 ',
            'discord_allowed_user_ids'    => ' 88,99 ',
        ];

        $this->assertTrue($this->invokePrivate('isAllowedChannel', ['123', $settings]));
        $this->assertFalse($this->invokePrivate('isAllowedChannel', ['12', $settings]));
        $this->assertTrue($this->invokePrivate('isAllowedUser', ['99', $settings]));
        $this->assertFalse($this->invokePrivate('isAllowedUser', ['9', $settings]));
    }

    /** @test */
    public function build_confirmation_components_returns_buttons_scoped_to_requester(): void {
        $components = $this->invokePrivate('buildConfirmationComponents', [[
            'content' => ['action_id' => '11111111-2222-3333-4444-555555555555'],
        ], '9988']);

        $this->assertCount(1, $components);
        $this->assertSame('Approve', $components[0]['components'][0]['label']);
        $this->assertSame(
            'wpoc:confirm:11111111-2222-3333-4444-555555555555:9988',
            $components[0]['components'][0]['custom_id']
        );
        $this->assertSame(
            'wpoc:reject:11111111-2222-3333-4444-555555555555:9988',
            $components[0]['components'][1]['custom_id']
        );
    }

    /** @test */
    public function build_confirmation_components_returns_empty_array_for_invalid_action_id(): void {
        $components = $this->invokePrivate('buildConfirmationComponents', [[
            'content' => ['action_id' => 'not-a-uuid'],
        ], '9988']);

        $this->assertSame([], $components);
    }

    /** @test */
    public function interaction_message_returns_ephemeral_truncated_response(): void {
        $message = str_repeat('x', 2005);
        $response = $this->invokePrivate('interactionMessage', [$message]);
        $data = $response->get_data();

        $this->assertSame(200, $response->get_status());
        $this->assertSame(4, $data['type']);
        $this->assertSame(64, $data['data']['flags']);
        $this->assertSame(1900, strlen($data['data']['content']));
        $this->assertStringEndsWith('...', $data['data']['content']);
    }

    /** @test */
    public function truncate_content_returns_done_for_empty_input(): void {
        $result = $this->invokePrivate('truncateContent', [" \n\t "]);

        $this->assertSame('Done.', $result);
    }

    /** @test */
    public function session_helpers_sanitize_key_and_delete_matching_transient(): void {
        $key = $this->invokePrivate('sessionKey', ['Channel:ABC', 'User:99']);
        $this->invokePrivate('clearSession', ['Channel:ABC', 'User:99']);

        $this->assertSame('wpoc_discord_session_channelabc_user99', $key);
        $this->assertSame([$key], $GLOBALS['wpoc_deleted_transients']);
    }

    /** @test */
    public function format_requester_prefix_only_mentions_numeric_ids(): void {
        $numeric = $this->invokePrivate('formatRequesterPrefix', ['12345']);
        $text    = $this->invokePrivate('formatRequesterPrefix', ['user-12345']);

        $this->assertSame('<@12345> ', $numeric);
        $this->assertSame('', $text);
    }

    /** @test */
    public function resolve_integration_user_id_returns_first_mock_admin_or_zero(): void {
        $GLOBALS['wpoc_mock_users'] = [7];
        $found = $this->invokePrivate('resolveIntegrationUserId');

        $GLOBALS['wpoc_mock_users'] = [];
        $missing = $this->invokePrivate('resolveIntegrationUserId');

        $this->assertSame(7, $found);
        $this->assertSame(0, $missing);
    }

    /** @test */
    public function valid_signature_check_accepts_signed_request_and_rejects_invalid_signature(): void {
        if (! function_exists('sodium_crypto_sign_keypair')) {
            $this->markTestSkipped('libsodium is not available in this PHP runtime.');
        }

        $keyPair = sodium_crypto_sign_keypair();
        $secret  = sodium_crypto_sign_secretkey($keyPair);
        $public  = sodium_crypto_sign_publickey($keyPair);
        $body    = '{"type":1}';
        $time    = '1712041234';
        $sig     = sodium_crypto_sign_detached($time . $body, $secret);

        $request = new \WP_REST_Request([
            'X-Signature-Ed25519' => bin2hex($sig),
            'X-Signature-Timestamp' => $time,
        ], $body);

        $valid = $this->invokePrivate('isValidSignature', [$request, $body, bin2hex($public)]);
        $invalid = $this->invokePrivate('isValidSignature', [$request, $body, str_repeat('a', 64)]);

        $this->assertTrue($valid);
        $this->assertFalse($invalid);
    }

    /**
     * Invoke a private controller method.
     *
     * @return mixed
     */
    private function invokePrivate(string $method, array $args = []) {
        $controller = new DiscordController();
        $reflection = new \ReflectionMethod($controller, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($controller, $args);
    }
}
