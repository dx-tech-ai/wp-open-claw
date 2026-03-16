<?php

declare(strict_types=1);

namespace OpenClaw;

defined('ABSPATH') || exit;

/**
 * Fired during plugin deactivation.
 */
class Deactivator {

    public static function deactivate(): void {
        // Clear transients — do NOT delete options here.
        delete_transient('wpoc_site_snapshot');

        // Unschedule any Action Scheduler tasks.
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions('wpoc_background_task');
        }
    }
}
