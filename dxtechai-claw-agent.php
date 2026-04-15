<?php
/**
 * Plugin Name:       DXTechAI Claw Agent
 * Plugin URI:        https://github.com/dx-tech-ai/wp-open-claw
 * Description:       AI Agent tự trị cho WordPress — thực thi hành động thật qua vòng lặp ReAct với Command Palette UI.
 * Version:           1.0.1
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            DX Tech AI
 * Author URI:        https://github.com/dx-tech-ai
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       dxtechai-claw-agent
 *
 * @package OpenClaw
 */

declare(strict_types=1);

// Prevent direct access.
defined('ABSPATH') || exit;

// Plugin constants.
define('WPOC_VERSION', '1.0.1');
define('WPOC_FILE', __FILE__);
define('WPOC_PATH', plugin_dir_path(__FILE__));
define('WPOC_URL', plugin_dir_url(__FILE__));
define('WPOC_BASENAME', plugin_basename(__FILE__));

// Composer autoloader.
if (file_exists(WPOC_PATH . 'vendor/autoload.php')) {
    require_once WPOC_PATH . 'vendor/autoload.php';
}

/**
 * Plugin activation.
 */
function wpoc_activate(): void {
    \OpenClaw\Activator::activate();
}
register_activation_hook(__FILE__, 'wpoc_activate');

/**
 * Plugin deactivation.
 */
function wpoc_deactivate(): void {
    \OpenClaw\Deactivator::deactivate();
}
register_deactivation_hook(__FILE__, 'wpoc_deactivate');

/**
 * Initialize the plugin on plugins_loaded.
 */
function wpoc_init(): void {

    // Boot Admin Settings & Dashboard.
    if (is_admin()) {
        $settings = new \OpenClaw\Admin\Settings();
        $settings->init();

        $dashboard = new \OpenClaw\Admin\Dashboard();
        $dashboard->init();
    }

    // SSE streaming via admin-ajax.php.
    $stream = new \OpenClaw\REST\StreamHandler();
    $stream->init();

    // Register REST API routes.
    add_action('rest_api_init', function (): void {
        $controller = new \OpenClaw\REST\AgentController();
        $controller->register_routes();

        // Telegram webhook route.
        $telegram = new \OpenClaw\Telegram\TelegramController();
        $telegram->register_webhook_route();

        // Discord interactions route.
        $discord = new \OpenClaw\Discord\DiscordController();
        $discord->register_routes();
    });

    // Authenticate Webhooks natively via WordPress REST API process.
    add_filter('determine_current_user', function ($user_id) {
        if (!empty($user_id)) {
            return $user_id;
        }

        if (empty($_SERVER['REQUEST_URI'])) {
            return $user_id;
        }

        $uri = sanitize_url( wp_unslash( $_SERVER['REQUEST_URI'] ) );
        
        // Fast paths to ignore non-webhook traces.
        if (strpos($uri, '/dxtechai-claw-agent/v1/telegram/webhook') === false && 
            strpos($uri, '/dxtechai-claw-agent/v1/discord/interactions') === false) {
            return $user_id;
        }

        $settings    = \OpenClaw\Admin\Settings::get_decrypted_settings();
        $mapped_user = absint($settings['agent_run_as_user_id'] ?? 0);

        if ($mapped_user <= 0) {
            return $user_id;
        }

        // --- Telegram Integration Auth ---
        if (strpos($uri, '/dxtechai-claw-agent/v1/telegram/webhook') !== false) {
            $received = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '' ) );
            $secret   = $settings['telegram_secret_token'] ?? '';
            if (!empty($secret) && !empty($received) && hash_equals($secret, $received)) {
                return $mapped_user;
            }
        }

        // --- Discord Integration Auth ---
        if (strpos($uri, '/dxtechai-claw-agent/v1/discord/interactions') !== false) {
            $signature = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_SIGNATURE_ED25519'] ?? '' ) );
            $timestamp = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_SIGNATURE_TIMESTAMP'] ?? '' ) );
            $publicKey = $settings['discord_public_key'] ?? '';

            if (
                !empty($signature) && 
                !empty($timestamp) && 
                !empty($publicKey) && 
                function_exists('sodium_crypto_sign_verify_detached')
            ) {
                // Determine user gracefully via stream context without consuming it exclusively.
                $rawBody = file_get_contents('php://input');
                
                if (ctype_xdigit(trim($signature)) && ctype_xdigit(trim($publicKey))) {
                    $decodedSignature = hex2bin(trim($signature));
                    $decodedKey       = hex2bin(trim($publicKey));

                    if ($decodedSignature !== false && $decodedKey !== false) {
                        try {
                            $isValid = sodium_crypto_sign_verify_detached($decodedSignature, $timestamp . $rawBody, $decodedKey);
                            if ($isValid) {
                                return $mapped_user;
                            }
                        } catch (\Throwable $e) {
                            return $user_id;
                        }
                    }
                }
            }
        }

        return $user_id;
    }, 20);
}
add_action('plugins_loaded', 'wpoc_init');
