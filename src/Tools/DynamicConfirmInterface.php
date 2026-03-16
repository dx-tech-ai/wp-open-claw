<?php

declare(strict_types=1);

namespace OpenClaw\Tools;

defined('ABSPATH') || exit;

/**
 * Optional interface for tools with mixed read/write actions.
 *
 * When a tool implements this interface, the Manager will call
 * requiresConfirmationFor() instead of requiresConfirmation()
 * to determine whether user confirmation is needed.
 */
interface DynamicConfirmInterface {

    /**
     * Determine if confirmation is needed based on the action parameters.
     *
     * @param  array $params  The parameters from the LLM function call.
     * @return bool           True if this specific action needs user confirmation.
     */
    public function requiresConfirmationFor(array $params): bool;
}
