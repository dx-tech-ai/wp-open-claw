<?php

declare(strict_types=1);

namespace OpenClaw\Telegram;

defined('ABSPATH') || exit;

use OpenClaw\Agent\Kernel;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Telegram Webhook Controller.
 *
 * Receives webhooks from Telegram Bot API and routes them
 * through the Open Claw Kernel ReAct loop.
 *
 * Endpoints:
 *   POST /open-claw/v1/telegram/webhook  — Telegram webhook
 *   POST /open-claw/v1/telegram/setup    — Register/remove webhook (admin only)
 */
class TelegramController {

    private const NAMESPACE = 'open-claw/v1';

    /**
     * Register REST API routes for Telegram.
     */
    public function register_webhook_route(): void {
        // Webhook endpoint (public — verified by secret token).
        register_rest_route(self::NAMESPACE, '/telegram/webhook', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handle_webhook'],
            'permission_callback' => '__return_true', // Public — auth via secret token.
        ]);

        // Setup endpoint (admin only — register/remove webhook).
        register_rest_route(self::NAMESPACE, '/telegram/setup', [
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
     * Handle incoming Telegram webhook.
     */
    public function handle_webhook(WP_REST_Request $request): WP_REST_Response {
        // Check if Telegram integration is enabled.
        $settings = \OpenClaw\Admin\Settings::get_decrypted_settings();
        if (empty($settings['telegram_enabled'])) {
            return new WP_REST_Response(['ok' => true], 200);
        }

        // Verify secret token.
        $secret   = $settings['telegram_secret_token'] ?? '';
        $received = $request->get_header('X-Telegram-Bot-Api-Secret-Token') ?? '';
        if (empty($secret) || ! hash_equals($secret, $received)) {
            return new WP_REST_Response(['ok' => true], 200); // Silent fail — don't leak info.
        }

        $body = $request->get_json_params();

        // Route based on update type.
        if (! empty($body['callback_query'])) {
            $this->handleCallbackQuery($body['callback_query'], $settings);
        } elseif (! empty($body['message']['text'])) {
            $this->handleMessage($body['message'], $settings);
        }

        // Always respond 200 to Telegram.
        return new WP_REST_Response(['ok' => true], 200);
    }

    /**
     * Handle a text message from Telegram.
     */
    private function handleMessage(array $message, array $settings): void {
        $chat_id = $message['chat']['id'] ?? 0;
        $text    = trim($message['text'] ?? '');

        // Check whitelist.
        if (! $this->isAllowedChat($chat_id, $settings)) {
            return;
        }

        // Handle /start command.
        if ($text === '/start') {
            $client = new TelegramClient();
            $help  = "🤖 *Open Claw Agent*\n\n";
            $help .= "AI trợ lý giúp bạn quản lý WordPress qua Telegram.\n";
            $help .= "Chỉ cần gõ yêu cầu bằng ngôn ngữ tự nhiên.\n\n";

            $help .= "📝 *Bài viết & Trang*\n";
            $help .= "• _Liệt kê 5 bài viết mới nhất_\n";
            $help .= "• _Tạo bài viết về chủ đề marketing_\n";
            $help .= "• _Cập nhật tiêu đề bài viết ID 123_\n\n";

            $help .= "🛍 *Sản phẩm & Đơn hàng*\n";
            $help .= "• _Xem danh sách sản phẩm đang bán_\n";
            $help .= "• _Kiểm tra đơn hàng mới hôm nay_\n";
            $help .= "• _Thống kê doanh thu tháng này_\n\n";

            $help .= "📊 *Báo cáo & Phân tích*\n";
            $help .= "• _Thống kê tổng quan website_\n";
            $help .= "• _Báo cáo top sản phẩm bán chạy_\n";
            $help .= "• _Phân tích lượt truy cập tuần qua_\n\n";

            $help .= "🔧 *Hệ thống*\n";
            $help .= "• _Kiểm tra trạng thái website_\n";
            $help .= "• _Xem danh sách plugin đang hoạt động_\n\n";

            $help .= "🌐 *Nghiên cứu web*\n";
            $help .= "• _Tìm hiểu xu hướng SEO 2026_\n";
            $help .= "• _Nghiên cứu đối thủ cạnh tranh_\n\n";

            $help .= "*Commands:*\n";
            $help .= "/start — Hiển thị trợ giúp\n";
            $help .= "/reset — Xóa session, bắt đầu hội thoại mới\n\n";

            $help .= "💡 *Mẹo:* Agent sẽ xin xác nhận trước khi thực hiện các thay đổi quan trọng (tạo, sửa, xóa).";

            $client->sendMessage($chat_id, $help);
            return;
        }

        // Handle /reset command.
        if ($text === '/reset') {
            $this->clearSession($chat_id);
            $client = new TelegramClient();
            $client->sendMessage($chat_id, "🔄 Session đã được xóa. Bạn có thể bắt đầu cuộc trò chuyện mới.");
            return;
        }

        // Skip empty messages.
        if (empty($text) || mb_strlen($text) < 2) {
            return;
        }

        // Set current user to admin for Kernel context.
        wp_set_current_user(1);

        // Create Kernel and restore session.
        $kernel     = new Kernel();
        $session_id = $this->getOrCreateSessionId($chat_id);
        $session    = get_transient($this->sessionKey($chat_id));

        if ($session) {
            $kernel->setMessages($session['messages'] ?? []);
            $kernel->setPendingActions($session['pending_actions'] ?? []);
        }

        // Send "typing" indicator.
        $client = new TelegramClient();

        // Run the ReAct loop.
        $steps = $kernel->handle($text);

        // Save session.
        set_transient($this->sessionKey($chat_id), [
            'messages'        => $kernel->getMessages(),
            'pending_actions' => $kernel->getPendingActions(),
            'session_id'      => $session_id,
        ], HOUR_IN_SECONDS);

        // Check for confirmation steps.
        $confirmation = StepFormatter::findConfirmation($steps);

        // Send formatted text messages (non-confirmation steps).
        $messages = StepFormatter::format($steps);
        foreach ($messages as $msg) {
            $client->sendMessage($chat_id, $msg);
        }

        // Send confirmation card if needed.
        if ($confirmation) {
            $cd = $confirmation['content'] ?? $confirmation;
            $client->sendConfirmationCard(
                $chat_id,
                $cd['action_id'] ?? '',
                $cd['tool_name'] ?? 'unknown',
                $cd['params'] ?? []
            );
        }
    }

    /**
     * Handle a callback query (inline keyboard button press).
     */
    private function handleCallbackQuery(array $callback_query, array $settings): void {
        $chat_id            = $callback_query['message']['chat']['id'] ?? 0;
        $message_id         = $callback_query['message']['message_id'] ?? 0;
        $callback_query_id  = $callback_query['id'] ?? '';
        $data               = $callback_query['data'] ?? '';

        // Check whitelist.
        if (! $this->isAllowedChat($chat_id, $settings)) {
            return;
        }

        $client = new TelegramClient();

        // Parse callback data: "confirm:{action_id}" or "reject:{action_id}"
        $parts = explode(':', $data, 2);
        if (count($parts) !== 2) {
            $client->answerCallbackQuery($callback_query_id, 'Invalid action.');
            return;
        }

        [$action, $action_id] = $parts;
        $approved = ($action === 'confirm');

        // Acknowledge button press.
        $client->answerCallbackQuery($callback_query_id, $approved ? '✅ Đang thực hiện...' : '❌ Đã từ chối');

        // Update the confirmation message.
        $status_text = $approved ? '✅ *Đã chấp nhận* — đang thực hiện...' : '❌ *Đã từ chối*';
        $client->editMessageText($chat_id, $message_id, $status_text);

        // Set current user to admin.
        wp_set_current_user(1);

        // Restore session and process.
        $session = get_transient($this->sessionKey($chat_id));
        if (! $session) {
            $client->sendMessage($chat_id, "⚠️ Session đã hết hạn. Vui lòng thử lại.");
            return;
        }

        $kernel = new Kernel();
        $kernel->setMessages($session['messages'] ?? []);
        $kernel->setPendingActions($session['pending_actions'] ?? []);

        if ($approved) {
            $steps = $kernel->confirmAction($action_id);
        } else {
            $result = $kernel->rejectAction($action_id);
            $steps  = [$result];
        }

        // Save updated session.
        set_transient($this->sessionKey($chat_id), [
            'messages'        => $kernel->getMessages(),
            'pending_actions' => $kernel->getPendingActions(),
            'session_id'      => $session['session_id'] ?? '',
        ], HOUR_IN_SECONDS);

        // Check for new confirmation in follow-up steps.
        $confirmation = StepFormatter::findConfirmation($steps);

        // Send result messages.
        $messages = StepFormatter::format($steps);
        foreach ($messages as $msg) {
            $client->sendMessage($chat_id, $msg);
        }

        // Send new confirmation card if chain action needs it.
        if ($confirmation) {
            $cd = $confirmation['content'] ?? $confirmation;
            $client->sendConfirmationCard(
                $chat_id,
                $cd['action_id'] ?? '',
                $cd['tool_name'] ?? 'unknown',
                $cd['params'] ?? []
            );
        }
    }

    /**
     * Handle webhook setup (register/remove).
     */
    public function handle_setup(WP_REST_Request $request): WP_REST_Response {
        $action   = $request->get_param('action');
        $settings = \OpenClaw\Admin\Settings::get_decrypted_settings();
        $token    = $settings['telegram_bot_token'] ?? '';

        if (empty($token)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Telegram Bot Token is required.',
            ], 400);
        }

        $client = new TelegramClient($token);

        // Status check.
        if ($action === 'status') {
            $botInfo     = $client->getMe();
            $webhookInfo = $client->getWebhookInfo();

            if (! $botInfo || ! $webhookInfo) {
                return new WP_REST_Response([
                    'success' => false,
                    'message' => 'Cannot connect to Telegram API. Check Bot Token.',
                    'status'  => 'error',
                ]);
            }

            $bot      = $botInfo['result'] ?? [];
            $webhook  = $webhookInfo['result'] ?? [];
            $url      = $webhook['url'] ?? '';
            $hasError = ! empty($webhook['last_error_message']);

            return new WP_REST_Response([
                'success'       => true,
                'status'        => empty($url) ? 'disconnected' : ($hasError ? 'error' : 'connected'),
                'bot_username'  => $bot['username'] ?? '',
                'webhook_url'   => $url,
                'pending_count' => $webhook['pending_update_count'] ?? 0,
                'last_error'    => $webhook['last_error_message'] ?? '',
                'last_error_at' => $webhook['last_error_date'] ?? 0,
            ]);
        }

        if ($action === 'register') {
            $secret      = $settings['telegram_secret_token'] ?? '';
            $webhook_url = rest_url(self::NAMESPACE . '/telegram/webhook');

            // Generate secret token if not set or contains invalid characters.
            // Telegram only allows: A-Z, a-z, 0-9, _ and - in secret_token.
            if (empty($secret) || preg_match('/[^A-Za-z0-9_\-]/', $secret)) {
                $chars  = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_-';
                $secret = '';
                for ($i = 0; $i < 64; $i++) {
                    $secret .= $chars[random_int(0, strlen($chars) - 1)];
                }
                $raw    = get_option('wpoc_settings', []);
                $raw['telegram_secret_token'] = \OpenClaw\Admin\Settings::encrypt_value($secret);
                update_option('wpoc_settings', $raw);
            }

            $result = $client->setWebhook($webhook_url, $secret);

            return new WP_REST_Response([
                'success' => (bool) $result,
                'message' => $result ? 'Webhook registered successfully.' : 'Failed to register webhook.',
                'url'     => $webhook_url,
            ]);
        }

        // Remove webhook.
        $result = $client->deleteWebhook();

        return new WP_REST_Response([
            'success' => (bool) $result,
            'message' => $result ? 'Webhook removed successfully.' : 'Failed to remove webhook.',
        ]);
    }

    /**
     * Check if a chat ID is in the allowed whitelist.
     */
    private function isAllowedChat($chat_id, array $settings): bool {
        $allowed_str = $settings['telegram_allowed_chat_ids'] ?? '';
        if (empty($allowed_str)) {
            return false;
        }

        $allowed = array_map('trim', explode(',', $allowed_str));
        return in_array((string) $chat_id, $allowed, true);
    }

    /**
     * Get or create a session ID for a Telegram chat.
     */
    private function getOrCreateSessionId($chat_id): string {
        $session = get_transient($this->sessionKey($chat_id));
        if ($session && ! empty($session['session_id'])) {
            return $session['session_id'];
        }
        return wp_generate_uuid4();
    }

    /**
     * Get transient key for a Telegram chat session.
     */
    private function sessionKey($chat_id): string {
        return 'wpoc_tg_session_' . sanitize_key((string) $chat_id);
    }

    /**
     * Clear session for a chat.
     */
    private function clearSession($chat_id): void {
        delete_transient($this->sessionKey($chat_id));
    }
}
