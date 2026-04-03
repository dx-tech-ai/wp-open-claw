<?php

declare(strict_types=1);

namespace OpenClaw\Tests\Discord;

use OpenClaw\Discord\DiscordClient;
use OpenClaw\Tests\AbstractTestCase;

/**
 * Unit tests for Discord\DiscordClient.
 *
 * These tests verify outbound request shape without touching the real network.
 *
 * @covers \OpenClaw\Discord\DiscordClient
 */
class DiscordClientTest extends AbstractTestCase {

    protected function setUp(): void {
        parent::setUp();

        $GLOBALS['wpoc_remote_request_log'] = [];
        $GLOBALS['wpoc_remote_request_callback'] = null;
    }

    /** @test */
    public function upsert_agent_command_uses_post_and_global_commands_path(): void {
        $GLOBALS['wpoc_remote_request_callback'] = static function (string $url, array $args): array {
            return [
                'response' => ['code' => 200],
                'body'     => '{"id":"123","name":"openclaw"}',
            ];
        };

        $client = new DiscordClient('bot-token');
        $result = $client->upsertAgentCommand('app-123');

        $request = $GLOBALS['wpoc_remote_request_log'][0];
        $body    = json_decode((string) $request['args']['body'], true);

        $this->assertSame(['id' => '123', 'name' => 'openclaw'], $result);
        $this->assertSame('https://discord.com/api/v10/applications/app-123/commands', $request['url']);
        $this->assertSame('POST', $request['args']['method']);
        $this->assertSame('openclaw', $body['name']);
        $this->assertSame('run', $body['options'][0]['name']);
        $this->assertSame('reset', $body['options'][1]['name']);
    }

    /** @test */
    public function upsert_agent_command_uses_guild_commands_path_when_guild_id_is_present(): void {
        $GLOBALS['wpoc_remote_request_callback'] = static function (): array {
            return [
                'response' => ['code' => 200],
                'body'     => '{"id":"123","name":"openclaw"}',
            ];
        };

        $client = new DiscordClient('bot-token');
        $client->upsertAgentCommand('app-123', 'openclaw', 'guild-456');

        $request = $GLOBALS['wpoc_remote_request_log'][0];

        $this->assertSame(
            'https://discord.com/api/v10/applications/app-123/guilds/guild-456/commands',
            $request['url']
        );
    }

    /** @test */
    public function get_command_by_name_returns_matching_command(): void {
        $GLOBALS['wpoc_remote_request_callback'] = static function (): array {
            return [
                'response' => ['code' => 200],
                'body'     => '[{"id":"1","name":"other"},{"id":"2","name":"openclaw"}]',
            ];
        };

        $client = new DiscordClient('bot-token');
        $result = $client->getCommandByName('app-123', 'openclaw');

        $this->assertSame(['id' => '2', 'name' => 'openclaw'], $result);
    }

    /** @test */
    public function get_command_by_name_returns_false_when_command_does_not_exist(): void {
        $GLOBALS['wpoc_remote_request_callback'] = static function (): array {
            return [
                'response' => ['code' => 200],
                'body'     => '[{"id":"1","name":"other"}]',
            ];
        };

        $client = new DiscordClient('bot-token');

        $this->assertFalse($client->getCommandByName('app-123', 'openclaw'));
    }

    /** @test */
    public function create_channel_message_omits_empty_optional_fields(): void {
        $GLOBALS['wpoc_remote_request_callback'] = static function (string $url, array $args): array {
            return [
                'response' => ['code' => 200],
                'body'     => $args['body'],
            ];
        };

        $client = new DiscordClient('bot-token');
        $result = $client->createChannelMessage('channel-1', 'Hello Discord');

        $request = $GLOBALS['wpoc_remote_request_log'][0];
        $body    = json_decode((string) $request['args']['body'], true);

        $this->assertSame(['content' => 'Hello Discord'], $result);
        $this->assertSame('https://discord.com/api/v10/channels/channel-1/messages', $request['url']);
        $this->assertArrayNotHasKey('components', $body);
        $this->assertArrayNotHasKey('allowed_mentions', $body);
    }

    /** @test */
    public function delete_command_returns_true_for_successful_delete(): void {
        $GLOBALS['wpoc_remote_request_callback'] = static function (): array {
            return [
                'response' => ['code' => 204],
                'body'     => '',
            ];
        };

        $client = new DiscordClient('bot-token');

        $this->assertTrue($client->deleteCommand('app-123', 'cmd-789'));
        $this->assertSame(
            'https://discord.com/api/v10/applications/app-123/commands/cmd-789',
            $GLOBALS['wpoc_remote_request_log'][0]['url']
        );
    }

    /** @test */
    public function request_returns_false_for_wp_error_response(): void {
        $GLOBALS['wpoc_remote_request_callback'] = static function () {
            return new \WP_Error('request_failed', 'No route to host');
        };

        $client = new DiscordClient('bot-token');

        $this->assertFalse($client->getCurrentBot());
    }

    /** @test */
    public function request_returns_false_for_non_success_status(): void {
        $GLOBALS['wpoc_remote_request_callback'] = static function (): array {
            return [
                'response' => ['code' => 400],
                'body'     => '{"message":"Invalid Form Body"}',
            ];
        };

        $client = new DiscordClient('bot-token');

        $this->assertFalse($client->upsertAgentCommand('app-123'));
    }
}
