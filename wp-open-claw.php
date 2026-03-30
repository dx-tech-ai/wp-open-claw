<?php
/**
 * Plugin Name:       Open Claw
 * Plugin URI:        https://github.com/dx-tech-ai/wp-open-claw
 * Description:       AI Agent tự trị cho WordPress — thực thi hành động thật qua vòng lặp ReAct với Command Palette UI.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            DX Tech AI
 * Author URI:        https://github.com/dx-tech-ai
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       open-claw
 *
 * @package OpenClaw
 */

declare(strict_types=1);

// Prevent direct access.
defined('ABSPATH') || exit;

// Plugin constants.
define('WPOC_VERSION', '1.0.0');
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
    });
}
add_action('plugins_loaded', 'wpoc_init');
