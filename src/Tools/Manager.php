<?php

declare(strict_types=1);

namespace OpenClaw\Tools;

defined('ABSPATH') || exit;

/**
 * Tool registry and dispatcher.
 *
 * Manages all registered Claw tools, provides their JSON Schemas
 * to the LLM, and dispatches function calls to the correct tool.
 */
class Manager {

    /** @var array<string, ToolInterface> */
    private array $tools = [];

    /**
     * Register a tool.
     */
    public function register(ToolInterface $tool): void {
        $this->tools[$tool->getName()] = $tool;
    }

    /**
     * Get all registered tool names.
     *
     * @return string[]
     */
    public function getToolNames(): array {
        return array_keys($this->tools);
    }

    /**
     * Check if a tool exists.
     */
    public function has(string $name): bool {
        return isset($this->tools[$name]);
    }

    /**
     * Get a single tool by name.
     */
    public function get(string $name): ?ToolInterface {
        return $this->tools[$name] ?? null;
    }

    /**
     * Export all tool schemas in OpenAI function-calling format.
     *
     * @return array<int, array{type: string, function: array}>
     */
    public function getSchemas(): array {
        $schemas = [];

        foreach ($this->tools as $tool) {
            $schemas[] = [
                'type'     => 'function',
                'function' => [
                    'name'        => $tool->getName(),
                    'description' => $tool->getDescription(),
                    'parameters'  => $tool->getSchema(),
                ],
            ];
        }

        return $schemas;
    }

    /**
     * Dispatch a function call to the matching tool.
     *
     * @param  string $name   Tool name from the LLM function call.
     * @param  array  $params Parameters from the LLM.
     * @return array{success: bool, data: mixed, message: string, requires_confirmation: bool}
     */
    public function dispatch(string $name, array $params): array {
        if (! $this->has($name)) {
            return [
                'success' => false,
                'data'    => null,
                'message' => sprintf('Tool "%s" not found. Available tools: %s', $name, implode(', ', $this->getToolNames())),
                'requires_confirmation' => false,
            ];
        }

        $tool = $this->tools[$name];

        // Check dynamic confirmation first (for mixed read/write tools).
        $needsConfirm = ($tool instanceof DynamicConfirmInterface)
            ? $tool->requiresConfirmationFor($params)
            : $tool->requiresConfirmation();

        // If tool requires confirmation, return early with pending status.
        if ($needsConfirm) {
            return [
                'success' => true,
                'data'    => $params,
                'message' => sprintf('Action "%s" requires your confirmation before execution.', $name),
                'requires_confirmation' => true,
                'tool_name' => $name,
            ];
        }

        // Execute immediately for read-only tools / read-only actions.
        return $this->executeDirectly($tool, $params);
    }

    /**
     * Execute a tool directly (after confirmation or for read-only tools).
     *
     * @param ToolInterface|string $tool
     * @param array $params
     * @return array{success: bool, data: mixed, message: string}
     */
    public function executeDirectly($tool, array $params): array {
        if (is_string($tool)) {
            $tool = $this->get($tool);
            if (! $tool) {
                return [
                    'success' => false,
                    'data'    => null,
                    'message' => 'Tool not found.',
                ];
            }
        }

        try {
            return $tool->execute($params);
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'data'    => null,
                'message' => sprintf('Tool execution error: %s', $e->getMessage()),
            ];
        }
    }
}

