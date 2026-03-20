<?php

declare(strict_types=1);

namespace OpenClaw\REST;

defined('ABSPATH') || exit;

use OpenClaw\Agent\Kernel;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * REST API controller for the Open Claw Agent.
 *
 * Endpoints:
 *   POST /open-claw/v1/agent/chat     — Send a message to the Agent
 *   POST /open-claw/v1/agent/confirm  — Approve/reject a pending action
 */
class AgentController {

    private const NAMESPACE = 'open-claw/v1';
    private const RATE_LIMIT = 20;
    private const RATE_WINDOW = 60;

    /**
     * Register REST API routes.
     */
    public function register_routes(): void {
        register_rest_route(self::NAMESPACE, '/agent/chat', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handle_chat'],
            'permission_callback' => [$this, 'check_permissions'],
            'args' => [
                'message' => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                    'validate_callback' => function ($value) {
                        return ! empty(trim($value));
                    },
                ],
                'session_id' => [
                    'required'          => false,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/agent/confirm', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handle_confirm'],
            'permission_callback' => [$this, 'check_permissions'],
            'args' => [
                'action_id' => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'approved' => [
                    'required'          => true,
                    'type'              => 'boolean',
                ],
                'session_id' => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);
    }

    /**
     * Permission check — must have manage_options capability.
     *
     * @return bool|\WP_Error
     */
    public function check_permissions(WP_REST_Request $request) {
        if (! current_user_can('manage_options')) {
            return new WP_Error(
                'rest_forbidden',
                esc_html__('You do not have permission to use Open Claw.', 'open-claw-wp'),
                ['status' => 403]
            );
        }

        // Rate limiting per user.
        $user_id = get_current_user_id();
        $key     = 'wpoc_rate_' . $user_id;
        $count   = (int) get_transient($key);

        if ($count >= self::RATE_LIMIT) {
            return new WP_Error(
                'rest_rate_limit',
                esc_html__('Rate limit exceeded. Please wait before sending more requests.', 'open-claw-wp'),
                ['status' => 429]
            );
        }

        set_transient($key, $count + 1, self::RATE_WINDOW);

        return true;
    }

    /**
     * Validate session_id format (UUID v4 only) and ownership.
     */
    private function validate_session_id(string $session_id): bool {
        return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $session_id);
    }

    /**
     * Get session transient key bound to current user.
     */
    private function session_key(string $session_id): string {
        return 'wpoc_session_' . get_current_user_id() . '_' . $session_id;
    }

    /**
     * Handle a chat message — runs the ReAct loop.
     */
    public function handle_chat(WP_REST_Request $request): WP_REST_Response {
        $message    = $request->get_param('message');
        $session_id = $request->get_param('session_id') ?: wp_generate_uuid4();

        if (! $this->validate_session_id($session_id)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Invalid session ID format.',
            ], 400);
        }

        $kernel = new Kernel();

        // Restore session if exists (bound to user).
        $session = get_transient($this->session_key($session_id));
        if ($session) {
            $kernel->setMessages($session['messages'] ?? []);
            $kernel->setPendingActions($session['pending_actions'] ?? []);
        }

        // Run the ReAct loop.
        $steps = $kernel->handle($message);

        // Save session state (bound to user).
        set_transient($this->session_key($session_id), [
            'messages'        => $kernel->getMessages(),
            'pending_actions' => $kernel->getPendingActions(),
        ], HOUR_IN_SECONDS);

        return new WP_REST_Response([
            'success'    => true,
            'session_id' => $session_id,
            'steps'      => $steps,
        ], 200);
    }

    /**
     * Handle action confirmation (approve/reject).
     *
     * On approval, the agent executes the confirmed action and then
     * resumes the ReAct loop to continue with any remaining tasks.
     */
    public function handle_confirm(WP_REST_Request $request): WP_REST_Response {
        $action_id  = $request->get_param('action_id');
        $approved   = $request->get_param('approved');
        $session_id = $request->get_param('session_id');

        if (! $this->validate_session_id($session_id) || ! $this->validate_session_id($action_id)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Invalid ID format.',
            ], 400);
        }

        // Restore session (bound to user).
        $session = get_transient($this->session_key($session_id));
        if (! $session) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Session expired. Please try again.',
            ], 404);
        }

        $kernel = new Kernel();
        $kernel->setMessages($session['messages'] ?? []);
        $kernel->setPendingActions($session['pending_actions'] ?? []);

        if ($approved) {
            // confirmAction now returns an array of steps (observation + resumed loop).
            $steps = $kernel->confirmAction($action_id);
        } else {
            $result = $kernel->rejectAction($action_id);
            $steps  = [$result]; // Wrap single result in array for consistency.
        }

        // Update session state (bound to user).
        set_transient($this->session_key($session_id), [
            'messages'        => $kernel->getMessages(),
            'pending_actions' => $kernel->getPendingActions(),
        ], HOUR_IN_SECONDS);

        return new WP_REST_Response([
            'success'    => true,
            'session_id' => $session_id,
            'steps'      => $steps,
        ], 200);
    }
}
