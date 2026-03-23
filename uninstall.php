<?php
/**
 * Uninstall script — runs when plugin is deleted.
 *
 * @package OpenClaw
 */

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Remove plugin options.
delete_option('wpoc_settings');

// Remove capabilities.
$wpoc_admin_role = get_role('administrator');
if ($wpoc_admin_role) {
    $wpoc_admin_role->remove_cap('manage_open_claw');
}

// Clear transients (including user-bound sessions).
global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time cleanup during uninstall, caching not applicable.
$wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
        $wpdb->esc_like('_transient_wpoc_') . '%',
        $wpdb->esc_like('_transient_timeout_wpoc_') . '%'
    )
);
