<?php

declare(strict_types=1);

namespace OpenClaw\Discord;

defined('ABSPATH') || exit;

/**
 * Discord REST API client.
 */
class DiscordClient {

    private const API_BASE = 'https://discord.com/api/v10';

    private string $token;

    public function __construct(string $token = '') {
        if (empty($token)) {
            $settings    = \OpenClaw\Admin\Settings::get_decrypted_settings();
            $this->token = $settings['discord_bot_token'] ?? '';
        } else {
            $this->token = $token;
        }
    }

    /**
     * Validate bot token by reading current bot profile.
     *
     * @return array|false
     */
    public function getCurrentBot() {
        return $this->request('GET', '/users/@me');
    }

    /**
     * List application commands for the chosen scope.
     *
     * @return array|false
     */
    public function listCommands(string $applicationId, string $guildId = '') {
        return $this->request('GET', $this->commandsPath($applicationId, $guildId));
    }

    /**
     * Find a command by name.
     *
     * @return array|false
     */
    public function getCommandByName(string $applicationId, string $name, string $guildId = '') {
        $commands = $this->listCommands($applicationId, $guildId);
        if (! is_array($commands)) {
            return false;
        }

        foreach ($commands as $command) {
            if (($command['name'] ?? '') === $name) {
                return $command;
            }
        }

        return false;
    }

    /**
     * Create or update the slash command.
     *
     * @return array|false
     */
    public function upsertAgentCommand(string $applicationId, string $commandName = 'openclaw', string $guildId = '') {
        $payload = [
            'name'        => $commandName,
            'description' => 'Control DXTechAI Claw Agent from Discord',
            'options'     => [
                [
                    'type'        => 1, // SUB_COMMAND
                    'name'        => 'run',
                    'description' => 'Run a prompt with DXTechAI Claw Agent',
                    'options'     => [
                        [
                            'type'        => 3, // STRING
                            'name'        => 'prompt',
                            'description' => 'What should DXTechAI Claw Agent do?',
                            'required'    => true,
                        ],
                    ],
                ],
                [
                    'type'        => 1, // SUB_COMMAND
                    'name'        => 'reset',
                    'description' => 'Clear your saved session in this channel',
                ],
            ],
        ];

        return $this->request(
            // Discord POST /commands acts as an upsert by name within the current scope.
            // PATCH requires a command ID, while PUT on the collection endpoint is bulk overwrite.
            'POST',
            $this->commandsPath($applicationId, $guildId),
            $payload
        );
    }

    /**
     * Delete a command by ID.
     */
    public function deleteCommand(string $applicationId, string $commandId, string $guildId = ''): bool {
        $result = $this->request(
            'DELETE',
            $this->commandsPath($applicationId, $guildId) . '/' . rawurlencode($commandId),
            null
        );

        return $result !== false;
    }

    /**
     * Send a message into a Discord channel using the bot token.
     *
     * @param array<int, array<string, mixed>> $components
     * @param array<string, mixed>             $allowedMentions
     * @return array|false
     */
    public function createChannelMessage(
        string $channelId,
        string $content,
        array $components = [],
        array $allowedMentions = []
    ) {
        $body = [
            'content' => $content,
        ];

        if (! empty($components)) {
            $body['components'] = $components;
        }

        if (! empty($allowedMentions)) {
            $body['allowed_mentions'] = $allowedMentions;
        }

        return $this->request(
            'POST',
            '/channels/' . rawurlencode($channelId) . '/messages',
            $body
        );
    }

    /**
     * Make an authenticated request to Discord API.
     *
     * @param string     $method HTTP method.
     * @param string     $path   API path (e.g. /users/@me).
     * @param array|null $body   JSON body.
     * @return array|false
     */
    private function request(string $method, string $path, ?array $body = null) {
        if (empty($this->token)) {
            return false;
        }

        $url = self::API_BASE . $path;
        $args = [
            'method'  => $method,
            'timeout' => 15,
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bot ' . $this->token,
            ],
        ];

        if ($body !== null) {
            $args['body'] = wp_json_encode($body);
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional debug logging for API errors.
            error_log('[OpenClaw Discord] API error: ' . $response->get_error_message());
            return false;
        }

        $status   = wp_remote_retrieve_response_code($response);
        $rawBody  = wp_remote_retrieve_body($response);
        $decoded  = $rawBody !== '' ? json_decode($rawBody, true) : [];

        if ($status < 200 || $status >= 300) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional debug logging for API failures.
            error_log('[OpenClaw Discord] API failed (' . $status . '): ' . wp_json_encode($decoded));
            return false;
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Build the API path for the configured command scope.
     */
    private function commandsPath(string $applicationId, string $guildId = ''): string {
        $base = '/applications/' . rawurlencode($applicationId);

        if ($guildId !== '') {
            return $base . '/guilds/' . rawurlencode($guildId) . '/commands';
        }

        return $base . '/commands';
    }
}
