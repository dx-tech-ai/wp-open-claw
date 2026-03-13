<?php

declare(strict_types=1);

namespace OpenClaw;

/**
 * Fired during plugin activation.
 */
class Activator {

    public static function activate(): void {
        self::check_requirements();
        self::set_default_options();
        self::add_capabilities();
    }

    private static function check_requirements(): void {
        if (version_compare(PHP_VERSION, '8.1', '<')) {
            deactivate_plugins(WPOC_BASENAME);
            wp_die(
                esc_html__('WP Open Claw requires PHP 8.1 or higher.', 'wp-open-claw'),
                'Plugin Activation Error',
                ['back_link' => true]
            );
        }

        global $wp_version;
        if (version_compare($wp_version, '6.4', '<')) {
            deactivate_plugins(WPOC_BASENAME);
            wp_die(
                esc_html__('WP Open Claw requires WordPress 6.4 or higher.', 'wp-open-claw'),
                'Plugin Activation Error',
                ['back_link' => true]
            );
        }
    }

    private static function set_default_options(): void {
        if (get_option('wpoc_settings') === false) {
            add_option('wpoc_settings', [
                'llm_provider'     => 'openai',
                'openai_api_key'   => '',
                'openai_model'     => 'gpt-4o',
                'anthropic_api_key'=> '',
                'anthropic_model'  => 'claude-sonnet-4-20250514',
                'google_cse_api_key' => '',
                'google_cse_cx'    => '',
                'max_iterations'   => 10,
            ]);
        }
    }

    private static function add_capabilities(): void {
        $admin = get_role('administrator');
        if ($admin) {
            $admin->add_cap('manage_open_claw');
        }
    }
}
