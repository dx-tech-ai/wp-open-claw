<?php

declare(strict_types=1);

namespace OpenClaw\REST;

defined('ABSPATH') || exit;

use OpenClaw\Agent\Kernel;

/**
 * SSE Streaming handler via admin-ajax.php.
 *
 * WordPress REST API forces Content-Type: application/json,
 * so we use admin-ajax for real-time Server-Sent Events streaming.
 *
 * Endpoint: admin-ajax.php?action=wpoc_stream_chat
 */
class StreamHandler {

    private const RATE_LIMIT = 20;
    private const RATE_WINDOW = 60;

    public function init(): void {
        add_action('wp_ajax_wpoc_stream_chat', [$this, 'handle_stream']);
    }

    /**
     * Handle SSE streaming chat request.
     */
    public function handle_stream(): void {
        // Auth check.
        if (! current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized', 403);
        }

        // Nonce check.
        if (! check_ajax_referer('wpoc_stream_nonce', '_nonce', false)) {
            wp_send_json_error('Invalid nonce', 403);
        }

        // Rate limiting.
        $user_id = get_current_user_id();
        $key     = 'wpoc_rate_' . $user_id;
        $count   = (int) get_transient($key);
        if ($count >= self::RATE_LIMIT) {
            wp_send_json_error('Rate limit exceeded', 429);
        }
        set_transient($key, $count + 1, self::RATE_WINDOW);

        $message    = sanitize_text_field(wp_unslash($_POST['message'] ?? ''));
        $session_id = sanitize_text_field(wp_unslash($_POST['session_id'] ?? ''));

        if (empty(trim($message))) {
            wp_send_json_error('Message is required', 400);
        }

        if (empty($session_id)) {
            $session_id = wp_generate_uuid4();
        }

        if (! $this->validate_session_id($session_id)) {
            wp_send_json_error('Invalid session ID', 400);
        }

        // Clean all output buffers — critical for SSE.
        while (ob_get_level()) {
            ob_end_clean();
        }

        // SSE headers.
        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');

        // Send session ID immediately.
        $this->sse_send(['type' => 'session', 'session_id' => $session_id]);

        $kernel = new Kernel();

        // Restore session.
        $session_key = 'wpoc_session_' . $user_id . '_' . $session_id;
        $session = get_transient($session_key);
        if ($session) {
            $kernel->setMessages($session['messages'] ?? []);
            $kernel->setPendingActions($session['pending_actions'] ?? []);
        }

        if (function_exists('set_time_limit')) {
            set_time_limit(300);
        }

        // Run ReAct loop with streaming.
        $kernel->handle($message, function (array $step) {
            $this->sse_send($step);
        });

        // Save session.
        set_transient($session_key, [
            'messages'        => $kernel->getMessages(),
            'pending_actions' => $kernel->getPendingActions(),
        ], HOUR_IN_SECONDS);

        $this->sse_send(['type' => 'done', 'session_id' => $session_id]);
        exit;
    }

    private function sse_send(array $data): void {
        echo 'data: ' . wp_json_encode($data) . "\n\n";
        if (ob_get_level()) {
            ob_flush();
        }
        flush();
    }

    private function validate_session_id(string $session_id): bool {
        return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $session_id);
    }
}
