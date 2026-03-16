<?php

declare(strict_types=1);

namespace OpenClaw\Tools;

defined('ABSPATH') || exit;

/**
 * Contract for all Claw tools.
 *
 * Each tool exposes a JSON Schema so the LLM knows how to call it,
 * and an execute() method that maps to real WordPress functions.
 */
interface ToolInterface {

    /**
     * Unique tool name used in function calling (e.g. "wp_content_manager").
     */
    public function getName(): string;

    /**
     * Human-readable description for the LLM.
     */
    public function getDescription(): string;

    /**
     * JSON Schema describing accepted parameters.
     *
     * @return array{type: string, properties: array, required: array}
     */
    public function getSchema(): array;

    /**
     * Execute the tool with given parameters and return an Observation.
     *
     * @param  array $params  Validated parameters from the LLM.
     * @return array{success: bool, data: mixed, message: string}
     */
    public function execute(array $params): array;

    /**
     * Whether this tool requires user confirmation before execution.
     *
     * Write operations (create/update/delete) should return true.
     * Read-only operations should return false.
     */
    public function requiresConfirmation(): bool;
}
