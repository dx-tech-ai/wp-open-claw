<?php

declare(strict_types=1);

namespace OpenClaw\Admin;

defined('ABSPATH') || exit;

/**
 * Admin Dashboard — enqueues Command Palette assets in WP-Admin only.
 */
class Dashboard {

    public function init(): void {
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('admin_footer', [$this, 'render_command_palette_container']);
    }

    /**
     * Enqueue CSS & JS only in the admin area.
     * This ensures ZERO impact on frontend page load (AC3).
     */
    public function enqueue_assets(): void {
        if (! current_user_can('manage_options')) {
            return;
        }

        wp_enqueue_style(
            'wpoc-command-palette',
            WPOC_URL . 'assets/css/command-palette.css',
            [],
            WPOC_VERSION
        );

        wp_enqueue_script(
            'wpoc-app',
            WPOC_URL . 'assets/js/app.js',
            [],
            WPOC_VERSION,
            true
        );

        wp_localize_script('wpoc-app', 'wpocData', [
            'restUrl'      => esc_url_raw(rest_url('open-claw/v1/')),
            'nonce'        => wp_create_nonce('wp_rest'),
            'adminUrl'     => admin_url(),
            'settingsUrl'  => admin_url('admin.php?page=wpoc-settings'),
            'streamUrl'    => admin_url('admin-ajax.php'),
            'streamNonce'  => wp_create_nonce('wpoc_stream_nonce'),
        ]);
    }

    /**
     * Render the Command Palette container in admin footer.
     */
    public function render_command_palette_container(): void {
        if (! current_user_can('manage_options')) {
            return;
        }
        ?>
        <div id="wpoc-command-palette" class="wpoc-overlay" style="display:none;" role="dialog" aria-label="<?php esc_attr_e('Open Claw Command Palette', 'open-claw'); ?>">
            <div class="wpoc-backdrop"></div>
            <div class="wpoc-palette">
                <div class="wpoc-header">
                    <div class="wpoc-logo">⚡ Open Claw</div>
                    <div style="display:flex;align-items:center;gap:4px;">
                        <button id="wpoc-new-chat" class="wpoc-new-chat" aria-label="New Chat" title="New Chat">🗑️</button>
                        <button class="wpoc-close" aria-label="Close">&times;</button>
                    </div>
                </div>
                <div class="wpoc-input-area">
                    <textarea id="wpoc-input"
                              class="wpoc-search-input"
                              rows="1"
                              placeholder="<?php esc_attr_e('Ask me to do something... (e.g. "Create a post about Da Nang")', 'open-claw'); ?>"
                              autocomplete="off"></textarea>
                    <button id="wpoc-send" class="wpoc-send-btn" aria-label="Send">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z"/>
                        </svg>
                    </button>
                </div>
                <div id="wpoc-log" class="wpoc-log-container"></div>
                <div id="wpoc-actions" class="wpoc-actions-container"></div>
                <div class="wpoc-footer">
                    <span class="wpoc-shortcut">Ctrl+G</span>
                    <span class="wpoc-status" id="wpoc-status"><?php esc_html_e('Ready', 'open-claw'); ?></span>
                </div>
            </div>
        </div>
        <?php
    }
}
