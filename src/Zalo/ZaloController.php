<?php

declare(strict_types=1);

namespace OpenClaw\Zalo;

defined('ABSPATH') || exit;

use OpenClaw\Agent\Kernel;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Zalo interactions controller.
 *
 * Endpoints:
 *   POST /dxtechai-claw-agent/v1/zalo/incoming - Receives messages from Zalo Bridge
 */
class ZaloController {

    private const NAMESPACE = 'dxtechai-claw-agent/v1';

    /**
     * Register Zalo REST routes.
     */
    public function register_routes(): void {
        register_rest_route(self::NAMESPACE, '/zalo/incoming', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handle_incoming'],
            'permission_callback' => '__return_true', // Verified by bridge secret.
        ]);

        register_rest_route(self::NAMESPACE, '/zalo/credentials', [
            'methods'             => 'GET',
            'callback'            => [$this, 'handle_credentials'],
            'permission_callback' => '__return_true', // Verified by bridge secret.
        ]);
    }

    /**
     * Handle fetching Zalo credentials for Bridge initialization.
     */
    public function handle_credentials(WP_REST_Request $request): WP_REST_Response {
        $settings = \OpenClaw\Admin\Settings::get_decrypted_settings();

        $secret = (string) ($request->get_header('X-Bridge-Secret') ?? '');
        $expectedSecret = $settings['zalo_bridge_secret'] ?? '';

        if ($secret === '' || $expectedSecret === '' || $secret !== $expectedSecret) {
            return new WP_REST_Response(['error' => 'Invalid bridge secret.'], 401);
        }

        if (empty($settings['zalo_enabled'])) {
            return new WP_REST_Response(['error' => 'Zalo integration is disabled.'], 403);
        }

        if (empty($settings['zalo_imei']) || empty($settings['zalo_cookies'])) {
            return new WP_REST_Response(['error' => 'Credentials not configured.'], 404);
        }

        return new WP_REST_Response([
            'imei'    => $settings['zalo_imei'],
            'cookies' => $settings['zalo_cookies'],
            'phone'   => $settings['zalo_phone'] ?? '',
        ]);
    }

    /**
     * Handle inbound message from Zalo Bridge service.
     */
    public function handle_incoming(WP_REST_Request $request): WP_REST_Response {
        $settings = \OpenClaw\Admin\Settings::get_decrypted_settings();

        // Verify bridge secret.
        $secret = (string) ($request->get_header('X-Bridge-Secret') ?? '');
        $expectedSecret = $settings['zalo_bridge_secret'] ?? '';

        if ($secret === '' || $expectedSecret === '' || $secret !== $expectedSecret) {
            return new WP_REST_Response(['error' => 'Invalid bridge secret.'], 401);
        }

        if (empty($settings['zalo_enabled'])) {
            return new WP_REST_Response(['error' => 'Zalo integration is disabled.'], 403);
        }

        $payload  = $request->get_json_params();
        $threadId = (string) ($payload['thread_id'] ?? '');
        $senderId = (string) ($payload['sender_id'] ?? '');
        $content  = trim((string) ($payload['message'] ?? ''));

        if ($threadId === '' || $content === '') {
            return new WP_REST_Response(['error' => 'Missing thread_id or message.'], 400);
        }

        // Check if sender is allowed.
        if (! $this->isAllowedUser($senderId, $settings)) {
            return new WP_REST_Response(['reply' => 'Bạn không có quyền sử dụng bot này.']);
        }

        // Handle special commands.
        $lowerContent = mb_strtolower($content);

        if ($lowerContent === '/reset' || $lowerContent === 'reset') {
            $this->clearSession($threadId, $senderId);
            return new WP_REST_Response(['reply' => '🔄 Phiên hội thoại đã được xóa.']);
        }

        // Process the prompt through Kernel.
        $integrationUserId = $this->resolveIntegrationUserId();
        if ($integrationUserId <= 0) {
            return new WP_REST_Response(['reply' => '⚠ Không tìm thấy tài khoản admin để xử lý.']);
        }

        $replyText = $this->runKernel($threadId, $senderId, $content, $integrationUserId);

        return new WP_REST_Response(['reply' => $replyText]);
    }

    /**
     * Run Kernel synchronously and return the formatted reply string.
     */
    private function runKernel(
        string $threadId,
        string $senderId,
        string $prompt,
        int $integrationUserId
    ): string {
        wp_set_current_user($integrationUserId);

        try {
            $kernel  = new Kernel();
            $session = get_transient($this->sessionKey($threadId, $senderId));
            if ($session) {
                $kernel->setMessages($session['messages'] ?? []);
                $kernel->setPendingActions($session['pending_actions'] ?? []);
            }

            $pendingActions = $kernel->getPendingActions();
            $lowerPrompt    = mb_strtolower(trim($prompt));

            // If there are pending actions and user replies "ok"/"no",
            // route to confirm/reject instead of starting a new ReAct loop.
            if (! empty($pendingActions) && in_array($lowerPrompt, ['ok', 'yes', 'có', 'đồng ý', 'approve', 'no', 'không', 'reject', 'cancel', 'hủy'], true)) {
                $actionId = array_key_first($pendingActions);
                $approved = in_array($lowerPrompt, ['ok', 'yes', 'có', 'đồng ý', 'approve'], true);

                if ($approved) {
                    $steps = $kernel->confirmAction($actionId);
                } else {
                    $result = $kernel->rejectAction($actionId);
                    $steps  = [$result];
                }

                // Save updated session after confirm/reject.
                set_transient($this->sessionKey($threadId, $senderId), [
                    'messages'        => $kernel->getMessages(),
                    'pending_actions' => $kernel->getPendingActions(),
                    'session_id'      => $session['session_id'] ?? wp_generate_uuid4(),
                ], HOUR_IN_SECONDS);

                $message      = StepFormatter::format($steps);
                $confirmation = StepFormatter::findConfirmation($steps);
                $content      = $message !== '' ? $message : ($approved ? '✅ Đã thực hiện.' : '❌ Đã từ chối.');

                if ($confirmation) {
                    $content .= "\n\n" . '🔐 Trả lời "ok" để phê duyệt hoặc "no" để từ chối.';
                }

                return $content;
            }

            // Normal flow — new request through ReAct loop.
            $steps = $kernel->handle($prompt);

            set_transient($this->sessionKey($threadId, $senderId), [
                'messages'        => $kernel->getMessages(),
                'pending_actions' => $kernel->getPendingActions(),
                'session_id'      => $session['session_id'] ?? wp_generate_uuid4(),
            ], HOUR_IN_SECONDS);

            $message      = StepFormatter::format($steps);
            $confirmation = StepFormatter::findConfirmation($steps);
            $content      = $message !== '' ? $message : 'Hoàn tất.';

            if ($confirmation) {
                $content .= "\n\n" . '🔐 Trả lời "ok" để phê duyệt hoặc "no" để từ chối.';
            }

            return $content;
        } catch (\Throwable $e) {
            return '⚠ Lỗi xử lý: ' . $e->getMessage();
        }
    }

    /**
     * Check if a Zalo user is allowed.
     */
    private function isAllowedUser(string $userId, array $settings): bool {
        $allowed = (string) ($settings['zalo_allowed_user_ids'] ?? '');
        if ($allowed === '') {
            return true; // No restriction = allow all.
        }

        $ids = array_map('trim', explode(',', $allowed));
        return in_array($userId, $ids, true);
    }

    /**
     * Resolve administrator account for execution.
     */
    private function resolveIntegrationUserId(): int {
        $admins = get_users([
            'role'    => 'administrator',
            'number'  => 1,
            'orderby' => 'ID',
            'order'   => 'ASC',
            'fields'  => 'ID',
        ]);

        return empty($admins) ? 0 : (int) $admins[0];
    }

    /**
     * Build transient key for Zalo session.
     */
    private function sessionKey(string $threadId, string $userId): string {
        return 'wpoc_zalo_session_' . sanitize_key($threadId . '_' . $userId);
    }

    /**
     * Clear session for the thread/user pair.
     */
    private function clearSession(string $threadId, string $userId): void {
        delete_transient($this->sessionKey($threadId, $userId));
    }


}
