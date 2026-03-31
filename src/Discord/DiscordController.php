<?php

declare(strict_types=1);

namespace OpenClaw\Discord;

defined('ABSPATH') || exit;

use OpenClaw\Agent\Kernel;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Discord interactions controller.
 *
 * Endpoints:
 *   POST /open-claw/v1/discord/interactions - Public Discord interaction webhook
 *   POST /open-claw/v1/discord/setup        - Admin setup actions
 */
class DiscordController {

    private const NAMESPACE = 'open-claw/v1';
    private const COMMAND_NAME = 'openclaw';
    private const MAX_CONTENT_LENGTH = 1900;
    private const ACK_FLAGS_EPHEMERAL = 64;

    /**
     * Register Discord REST routes.
     */
    public function register_routes(): void {
        register_rest_route(self::NAMESPACE, '/discord/interactions', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handle_interactions'],
            'permission_callback' => '__return_true', // Public endpoint; verified by Discord signature.
        ]);

        register_rest_route(self::NAMESPACE, '/discord/setup', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handle_setup'],
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
            'args' => [
                'action' => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                    'validate_callback' => function ($value) {
                        return in_array($value, ['register', 'remove', 'status'], true);
                    },
                ],
            ],
        ]);
    }

    /**
     * Handle inbound Discord interactions webhook.
     */
    public function handle_interactions(WP_REST_Request $request): WP_REST_Response {
        $settings = \OpenClaw\Admin\Settings::get_decrypted_settings();
        $rawBody  = $request->get_body();

        if (! $this->isValidSignature($request, $rawBody, $settings['discord_public_key'] ?? '')) {
            return new WP_REST_Response(['error' => 'Invalid Discord signature.'], 401);
        }

        $payload = $request->get_json_params();
        $type    = (int) ($payload['type'] ?? 0);

        // Discord endpoint verification ping.
        if ($type === 1) {
            return new WP_REST_Response(['type' => 1], 200);
        }

        if (empty($settings['discord_enabled'])) {
            return $this->interactionMessage('Discord integration is disabled.');
        }

        if ($type === 2) {
            return $this->handleCommand($payload, $settings);
        }

        if ($type === 3) {
            return $this->handleComponent($payload, $settings);
        }

        return $this->interactionMessage('Unsupported Discord interaction.');
    }

    /**
     * Handle setup/status actions from admin settings page.
     */
    public function handle_setup(WP_REST_Request $request): WP_REST_Response {
        $action   = $request->get_param('action');
        $settings = \OpenClaw\Admin\Settings::get_decrypted_settings();

        $token  = $settings['discord_bot_token'] ?? '';
        $appId  = $settings['discord_application_id'] ?? '';
        $client = new DiscordClient($token);

        if ($action === 'status') {
            $bot     = ! empty($token) ? $client->getCurrentBot() : false;
            $command = (! empty($token) && ! empty($appId))
                ? $client->getCommandByName($appId, self::COMMAND_NAME)
                : false;

            return new WP_REST_Response([
                'success'          => true,
                'status'           => $bot ? 'connected' : 'disconnected',
                'bot_name'         => $bot['username'] ?? '',
                'command_registered'=> (bool) $command,
                'interaction_url'  => rest_url(self::NAMESPACE . '/discord/interactions'),
                'message'          => $bot ? 'Discord credentials look valid.' : 'Discord Bot Token not set or invalid.',
            ]);
        }

        if (empty($token) || empty($appId)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Discord Bot Token and Application ID are required.',
            ], 400);
        }

        if ($action === 'register') {
            $result = $client->upsertAgentCommand($appId, self::COMMAND_NAME);
            return new WP_REST_Response([
                'success' => (bool) $result,
                'message' => $result
                    ? 'Slash command /' . self::COMMAND_NAME . ' registered or updated.'
                    : 'Failed to register slash command.',
                'interaction_url' => rest_url(self::NAMESPACE . '/discord/interactions'),
            ]);
        }

        // remove
        $command = $client->getCommandByName($appId, self::COMMAND_NAME);
        if (! $command) {
            return new WP_REST_Response([
                'success' => true,
                'message' => 'Slash command is already removed.',
            ]);
        }

        $deleted = $client->deleteCommand($appId, (string) ($command['id'] ?? ''));
        return new WP_REST_Response([
            'success' => $deleted,
            'message' => $deleted ? 'Slash command removed.' : 'Failed to remove slash command.',
        ]);
    }

    /**
     * Handle slash command interaction.
     */
    private function handleCommand(array $payload, array $settings): WP_REST_Response {
        $channelId = (string) ($payload['channel_id'] ?? '');
        $userId    = $this->extractUserId($payload);

        if (! $this->isAllowedChannel($channelId, $settings)) {
            return $this->interactionMessage('This channel is not allowed for Open Claw.');
        }

        $commandName = (string) ($payload['data']['name'] ?? '');
        if ($commandName !== self::COMMAND_NAME) {
            return $this->interactionMessage('Unknown command.');
        }

        $prompt = $this->extractPrompt($payload['data']['options'] ?? []);
        if ($prompt === '') {
            return $this->interactionMessage('Please provide a prompt.');
        }

        $integrationUserId = $this->resolveIntegrationUserId();
        if ($integrationUserId <= 0) {
            return $this->interactionMessage('No administrator account available for Discord execution.');
        }

        $this->sendImmediateResponse([
            'type' => 4,
            'data' => [
                'content' => 'Open Claw is processing your request.',
                'flags'   => self::ACK_FLAGS_EPHEMERAL,
            ],
        ]);

        $this->processCommand($channelId, $userId, $prompt, $settings, $integrationUserId);
        exit;
    }

    /**
     * Handle button component interaction (confirm/reject).
     */
    private function handleComponent(array $payload, array $settings): WP_REST_Response {
        $channelId = (string) ($payload['channel_id'] ?? '');
        $userId    = $this->extractUserId($payload);
        $customId  = (string) ($payload['data']['custom_id'] ?? '');

        if (! $this->isAllowedChannel($channelId, $settings)) {
            return $this->interactionMessage('This channel is not allowed for Open Claw.');
        }

        if (! preg_match('/^wpoc:(confirm|reject):([0-9a-f\-]{36}):([^:]+)$/i', $customId, $matches)) {
            return $this->interactionMessage('Invalid action button.');
        }

        $approved = strtolower($matches[1]) === 'confirm';
        $actionId = $matches[2];
        $ownerId  = $matches[3];

        if ($ownerId !== $userId) {
            return $this->interactionMessage('Only the user who started this request can confirm it.');
        }

        $integrationUserId = $this->resolveIntegrationUserId();
        if ($integrationUserId <= 0) {
            return $this->interactionMessage('No administrator account available for Discord execution.');
        }
        $session = get_transient($this->sessionKey($channelId, $userId));
        if (! $session) {
            return $this->interactionMessage('Session expired. Please run /' . self::COMMAND_NAME . ' again.');
        }

        $this->sendImmediateResponse([
            'type' => 7,
            'data' => [
                'content'    => $approved ? 'Approval received. Processing...' : 'Request rejected.',
                'components' => [],
            ],
        ]);

        $this->processComponent(
            $channelId,
            $userId,
            $actionId,
            $approved,
            $settings,
            $integrationUserId,
            $session
        );
        exit;
    }

    /**
     * Return a simple Discord interaction message response.
     */
    private function interactionMessage(string $message): WP_REST_Response {
        return new WP_REST_Response([
            'type' => 4,
            'data' => [
                'content' => $this->truncateContent($message),
            ],
        ], 200);
    }

    /**
     * Build approve/reject buttons for a confirmation step.
     */
    private function buildConfirmationComponents(array $step, string $userId): array {
        $content = $step['content'] ?? [];
        $actionId = (string) ($content['action_id'] ?? '');

        if (! preg_match('/^[0-9a-f\-]{36}$/i', $actionId)) {
            return [];
        }

        return [
            [
                'type'       => 1, // ACTION_ROW
                'components' => [
                    [
                        'type'      => 2, // BUTTON
                        'style'     => 3, // SUCCESS
                        'label'     => 'Approve',
                        'custom_id' => 'wpoc:confirm:' . $actionId . ':' . $userId,
                    ],
                    [
                        'type'      => 2, // BUTTON
                        'style'     => 4, // DANGER
                        'label'     => 'Reject',
                        'custom_id' => 'wpoc:reject:' . $actionId . ':' . $userId,
                    ],
                ],
            ],
        ];
    }

    /**
     * Execute a slash command after the interaction has been acknowledged.
     */
    private function processCommand(
        string $channelId,
        string $userId,
        string $prompt,
        array $settings,
        int $integrationUserId
    ): void {
        $this->prepareForBackgroundWork();
        wp_set_current_user($integrationUserId);

        try {
            $kernel  = new Kernel();
            $session = get_transient($this->sessionKey($channelId, $userId));
            if ($session) {
                $kernel->setMessages($session['messages'] ?? []);
                $kernel->setPendingActions($session['pending_actions'] ?? []);
            }

            $steps = $kernel->handle($prompt);

            set_transient($this->sessionKey($channelId, $userId), [
                'messages'        => $kernel->getMessages(),
                'pending_actions' => $kernel->getPendingActions(),
                'session_id'      => $session['session_id'] ?? wp_generate_uuid4(),
            ], HOUR_IN_SECONDS);

            $this->postStepsToChannel($channelId, $userId, $steps, 'Completed.', $settings);
        } catch (\Throwable $e) {
            $this->postPlainMessage(
                $channelId,
                $userId,
                'Discord command failed: ' . $e->getMessage(),
                [],
                $settings
            );
        }
    }

    /**
     * Execute an approve/reject action after the interaction has been acknowledged.
     *
     * @param array<string, mixed> $session
     */
    private function processComponent(
        string $channelId,
        string $userId,
        string $actionId,
        bool $approved,
        array $settings,
        int $integrationUserId,
        array $session
    ): void {
        $this->prepareForBackgroundWork();
        wp_set_current_user($integrationUserId);

        try {
            $kernel = new Kernel();
            $kernel->setMessages($session['messages'] ?? []);
            $kernel->setPendingActions($session['pending_actions'] ?? []);

            if ($approved) {
                $steps = $kernel->confirmAction($actionId);
            } else {
                $steps = [$kernel->rejectAction($actionId)];
            }

            set_transient($this->sessionKey($channelId, $userId), [
                'messages'        => $kernel->getMessages(),
                'pending_actions' => $kernel->getPendingActions(),
                'session_id'      => $session['session_id'] ?? wp_generate_uuid4(),
            ], HOUR_IN_SECONDS);

            $this->postStepsToChannel($channelId, $userId, $steps, 'Processed.', $settings);
        } catch (\Throwable $e) {
            $this->postPlainMessage(
                $channelId,
                $userId,
                'Discord action failed: ' . $e->getMessage(),
                [],
                $settings
            );
        }
    }

    /**
     * Convert Kernel steps into a Discord channel message.
     */
    private function postStepsToChannel(
        string $channelId,
        string $userId,
        array $steps,
        string $fallback,
        array $settings
    ): void {
        $message      = StepFormatter::format($steps);
        $confirmation = StepFormatter::findConfirmation($steps);
        $components   = $confirmation ? $this->buildConfirmationComponents($confirmation, $userId) : [];
        $content      = $message !== '' ? $message : $fallback;

        $this->postPlainMessage($channelId, $userId, $content, $components, $settings);
    }

    /**
     * Post a standard channel message via the bot.
     *
     * @param array<int, array<string, mixed>> $components
     */
    private function postPlainMessage(
        string $channelId,
        string $userId,
        string $content,
        array $components,
        array $settings
    ): void {
        $client  = new DiscordClient((string) ($settings['discord_bot_token'] ?? ''));
        $message = $this->truncateContent($this->formatRequesterPrefix($userId) . $content);

        $allowedMentions = [];
        if (ctype_digit($userId)) {
            $allowedMentions = [
                'parse' => [],
                'users' => [$userId],
            ];
        }

        $result = $client->createChannelMessage($channelId, $message, $components, $allowedMentions);
        if ($result === false) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug logging for Discord delivery failures.
            error_log('[OpenClaw Discord] Failed to post channel message.');
        }
    }

    /**
     * Send the required interaction response immediately, then continue work.
     *
     * @param array<string, mixed> $payload
     */
    private function sendImmediateResponse(array $payload): void {
        if (function_exists('ignore_user_abort')) {
            ignore_user_abort(true);
        }

        $json = wp_json_encode($payload);

        nocache_headers();
        status_header(200);
        header('Content-Type: application/json; charset=' . get_option('blog_charset'));
        header('Connection: close');
        header('Content-Length: ' . strlen($json));

        echo $json;

        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
            return;
        }

        if (function_exists('session_write_close')) {
            session_write_close();
        }

        while (ob_get_level() > 0) {
            @ob_end_flush();
        }

        flush();
    }

    /**
     * Prepare the current request for long-running work after the interaction ack.
     */
    private function prepareForBackgroundWork(): void {
        if (function_exists('set_time_limit')) {
            // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
            set_time_limit(300);
        }
    }

    /**
     * Prefix channel responses so the requester is obvious in shared channels.
     */
    private function formatRequesterPrefix(string $userId): string {
        return ctype_digit($userId) ? '<@' . $userId . '> ' : '';
    }

    /**
     * Validate Discord request signature.
     */
    private function isValidSignature(WP_REST_Request $request, string $rawBody, string $publicKey): bool {
        $signature = (string) ($request->get_header('X-Signature-Ed25519') ?? '');
        $timestamp = (string) ($request->get_header('X-Signature-Timestamp') ?? '');

        if ($signature === '' || $timestamp === '' || $publicKey === '') {
            return false;
        }

        if (! function_exists('sodium_crypto_sign_verify_detached')) {
            return false;
        }

        if (! ctype_xdigit(trim($signature)) || ! ctype_xdigit(trim($publicKey))) {
            return false;
        }

        $decodedSignature = hex2bin(trim($signature));
        $decodedKey       = hex2bin(trim($publicKey));

        if ($decodedSignature === false || $decodedKey === false) {
            return false;
        }

        return sodium_crypto_sign_verify_detached(
            $decodedSignature,
            $timestamp . $rawBody,
            $decodedKey
        );
    }

    /**
     * Extract prompt option from slash command payload.
     */
    private function extractPrompt(array $options): string {
        foreach ($options as $option) {
            if (($option['name'] ?? '') === 'prompt') {
                return trim((string) ($option['value'] ?? ''));
            }
        }

        return '';
    }

    /**
     * Extract Discord user ID from interaction payload.
     */
    private function extractUserId(array $payload): string {
        if (! empty($payload['member']['user']['id'])) {
            return (string) $payload['member']['user']['id'];
        }
        if (! empty($payload['user']['id'])) {
            return (string) $payload['user']['id'];
        }

        return 'unknown';
    }

    /**
     * Check if channel is allowed by settings.
     */
    private function isAllowedChannel(string $channelId, array $settings): bool {
        $allowed = (string) ($settings['discord_allowed_channel_ids'] ?? '');
        if ($allowed === '') {
            return false;
        }

        $ids = array_map('trim', explode(',', $allowed));
        return in_array($channelId, $ids, true);
    }

    /**
     * Resolve a deterministic administrator account for integration execution.
     */
    private function resolveIntegrationUserId(): int {
        $admins = get_users([
            'role'    => 'administrator',
            'number'  => 1,
            'orderby' => 'ID',
            'order'   => 'ASC',
            'fields'  => 'ID',
        ]);

        if (empty($admins)) {
            return 0;
        }

        return (int) $admins[0];
    }

    /**
     * Build transient key for Discord session.
     */
    private function sessionKey(string $channelId, string $userId): string {
        return 'wpoc_discord_session_' . sanitize_key($channelId . '_' . $userId);
    }

    /**
     * Keep Discord message within safe content limit.
     */
    private function truncateContent(string $content): string {
        $content = trim($content);
        if ($content === '') {
            return 'Done.';
        }

        return mb_strlen($content) > self::MAX_CONTENT_LENGTH
            ? mb_substr($content, 0, self::MAX_CONTENT_LENGTH - 3) . '...'
            : $content;
    }
}
