<?php

declare(strict_types=1);

namespace OpenClaw\REST;

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
     */
    public function check_permissions(WP_REST_Request $request): bool|WP_Error {
        if (! current_user_can('manage_options')) {
            return new WP_Error(
                'rest_forbidden',
                esc_html__('You do not have permission to use Open Claw.', 'wp-open-claw'),
                ['status' => 403]
            );
        }

        return true;
    }

    /**
     * Handle a chat message — runs the ReAct loop.
     */
    public function handle_chat(WP_REST_Request $request): WP_REST_Response {
        $message    = $request->get_param('message');
        $session_id = $request->get_param('session_id') ?: wp_generate_uuid4();

        $kernel = new Kernel();

        // Restore session if exists.
        $session = get_transient('wpoc_session_' . $session_id);
        if ($session) {
            $kernel->setMessages($session['messages'] ?? []);
            $kernel->setPendingActions($session['pending_actions'] ?? []);
        }

        // Run the ReAct loop.
        $steps = $kernel->handle($message);

        // Save session state.
        set_transient('wpoc_session_' . $session_id, [
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

        // Restore session.
        $session = get_transient('wpoc_session_' . $session_id);
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

        // Update session state (may have new pending actions from resumed loop).
        set_transient('wpoc_session_' . $session_id, [
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
