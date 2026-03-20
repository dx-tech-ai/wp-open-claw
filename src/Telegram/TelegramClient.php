<?php

declare(strict_types=1);

namespace OpenClaw\Telegram;

defined('ABSPATH') || exit;

/**
 * Telegram Bot API client.
 *
 * Wraps wp_remote_post() calls to the Telegram Bot API.
 */
class TelegramClient {

    private const API_BASE = 'https://api.telegram.org/bot';

    private string $token;

    public function __construct(string $token = '') {
        if (empty($token)) {
            $settings    = \OpenClaw\Admin\Settings::get_decrypted_settings();
            $this->token = $settings['telegram_bot_token'] ?? '';
        } else {
            $this->token = $token;
        }
    }

    /**
     * Send a text message.
     *
     * @param int|string $chat_id    Telegram chat ID.
     * @param string     $text       Message text (Markdown supported).
     * @param array|null $reply_markup Optional inline keyboard or other markup.
     * @return array|false Telegram response or false on failure.
     */
    public function sendMessage($chat_id, string $text, ?array $reply_markup = null, bool $use_markdown = true) {
        $body = [
            'chat_id'    => $chat_id,
            'text'       => mb_substr($text, 0, 4096),
        ];

        if ($use_markdown) {
            $body['parse_mode'] = 'Markdown';
        }

        if ($reply_markup) {
            $body['reply_markup'] = wp_json_encode($reply_markup);
        }

        $result = $this->request('sendMessage', $body);

        // Retry without Markdown if Telegram can't parse entities.
        if ($result === false && $use_markdown) {
            unset($body['parse_mode']);
            $result = $this->request('sendMessage', $body);
        }

        return $result;
    }

    /**
     * Send a confirmation card with inline keyboard.
     *
     * @param int|string $chat_id    Telegram chat ID.
     * @param string     $action_id  Pending action UUID.
     * @param string     $session_id Session UUID.
     * @param string     $tool_name  Tool name for display.
     * @param array      $params     Tool parameters for display.
     * @return array|false
     */
    public function sendConfirmationCard($chat_id, string $action_id, string $tool_name, array $params) {
        $params_text = '';
        foreach ($params as $key => $value) {
            $display_value = is_array($value) ? wp_json_encode($value) : (string) $value;
            $display_value = mb_substr($display_value, 0, 100);
            $params_text  .= "  • `{$key}`: {$display_value}\n";
        }
        $params_text = mb_substr($params_text, 0, 2000);

        $text = "⚠️ *Xác nhận hành động*\n\n"
              . "🔧 *Tool:* `{$tool_name}`\n"
              . "📋 *Parameters:*\n{$params_text}\n"
              . "_Bạn có muốn thực hiện hành động này?_";

        $reply_markup = [
            'inline_keyboard' => [
                [
                    [
                        'text'          => '✅ Approve',
                        'callback_data' => "confirm:{$action_id}",
                    ],
                    [
                        'text'          => '❌ Reject',
                        'callback_data' => "reject:{$action_id}",
                    ],
                ],
            ],
        ];

        return $this->sendMessage($chat_id, $text, $reply_markup);
    }

    /**
     * Answer a callback query (acknowledge button press).
     *
     * @param string $callback_query_id
     * @param string $text Optional toast text.
     * @return array|false
     */
    public function answerCallbackQuery(string $callback_query_id, string $text = '') {
        return $this->request('answerCallbackQuery', [
            'callback_query_id' => $callback_query_id,
            'text'              => $text,
        ]);
    }

    /**
     * Edit a previously sent message.
     *
     * @param int|string $chat_id
     * @param int        $message_id
     * @param string     $text
     * @return array|false
     */
    public function editMessageText($chat_id, int $message_id, string $text) {
        return $this->request('editMessageText', [
            'chat_id'    => $chat_id,
            'message_id' => $message_id,
            'text'       => mb_substr($text, 0, 4096),
            'parse_mode' => 'Markdown',
        ]);
    }

    /**
     * Register webhook with Telegram.
     *
     * @param string $url          Webhook URL.
     * @param string $secret_token Secret token for verification.
     * @return array|false
     */
    public function setWebhook(string $url, string $secret_token) {
        return $this->request('setWebhook', [
            'url'                  => $url,
            'secret_token'         => $secret_token,
            'allowed_updates'      => wp_json_encode(['message', 'callback_query']),
            'drop_pending_updates' => true,
        ]);
    }

    /**
     * Remove webhook.
     *
     * @return array|false
     */
    public function deleteWebhook() {
        return $this->request('deleteWebhook', [
            'drop_pending_updates' => true,
        ]);
    }

    /**
     * Get basic bot info (for validation).
     *
     * @return array|false
     */
    public function getMe() {
        return $this->request('getMe');
    }

    /**
     * Make a request to the Telegram Bot API.
     *
     * @param string $method API method name.
     * @param array  $body   Request body.
     * @return array|false Decoded response body or false on failure.
     */
    private function request(string $method, array $body = []) {
        if (empty($this->token)) {
            return false;
        }

        $url = self::API_BASE . $this->token . '/' . $method;

        $response = wp_remote_post($url, [
            'timeout' => 15,
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => wp_json_encode($body),
        ]);

        if (is_wp_error($response)) {
            error_log('[OpenClaw Telegram] API error: ' . $response->get_error_message());
            return false;
        }

        $status = wp_remote_retrieve_response_code($response);
        $data   = json_decode(wp_remote_retrieve_body($response), true);

        if ($status !== 200 || empty($data['ok'])) {
            error_log('[OpenClaw Telegram] API failed: ' . wp_json_encode($data));
            return false;
        }

        return $data;
    }
}
