<?php

declare(strict_types=1);

namespace OpenClaw\Agent;

defined('ABSPATH') || exit;

use OpenClaw\LLM\ClientInterface;
use OpenClaw\LLM\OpenAIClient;
use OpenClaw\LLM\AnthropicClient;
use OpenClaw\LLM\GeminiClient;
use OpenClaw\Tools\Manager;
use OpenClaw\Actions\ContentTool;
use OpenClaw\Actions\SystemTool;
use OpenClaw\Actions\ResearchTool;
use OpenClaw\Actions\TaxonomyTool;
use OpenClaw\Actions\MediaTool;
use OpenClaw\Actions\PageTool;
use OpenClaw\Actions\UserInspector;
use OpenClaw\Actions\AnalyticsReader;
use OpenClaw\Actions\ProductTool;
use OpenClaw\Actions\OrderTool;
use OpenClaw\Actions\CustomerTool;

/**
 * Agent Kernel — the ReAct loop engine.
 *
 * Flow: Input → Reason (LLM) → Act (Tool) → Observe → Repeat
 */
class Kernel {

    private ClientInterface $llm;
    private Manager $tools;
    private ContextProvider $context;
    private int $maxIterations;

    /** @var array Conversation message history for this session. */
    private array $messages = [];

    /** @var array Pending actions awaiting user confirmation. */
    private array $pendingActions = [];

    public function __construct() {
        $settings = get_option('wpoc_settings', []);

        // Initialize LLM client based on provider setting.
        $provider = $settings['llm_provider'] ?? 'openai';
        switch ($provider) {
            case 'anthropic':
                $this->llm = new AnthropicClient();
                break;
            case 'gemini':
                $this->llm = new GeminiClient();
                break;
            default:
                $this->llm = new OpenAIClient();
                break;
        }

        $this->maxIterations = absint($settings['max_iterations'] ?? 10);

        // Initialize tools.
        $this->tools = new Manager();
        $this->tools->register(new ContentTool());
        $this->tools->register(new SystemTool());
        $this->tools->register(new ResearchTool());
        $this->tools->register(new TaxonomyTool());
        $this->tools->register(new MediaTool());
        $this->tools->register(new PageTool());
        $this->tools->register(new UserInspector());
        $this->tools->register(new AnalyticsReader());

        // WooCommerce tools (only if WooCommerce is active).
        if (class_exists('WooCommerce')) {
            $this->tools->register(new ProductTool());
            $this->tools->register(new OrderTool());
            $this->tools->register(new CustomerTool());
        }

        // Context provider.
        $this->context = new ContextProvider();
    }

    /**
     * Handle a user message through the ReAct loop.
     *
     * Returns an array of "steps" that represent the Agent's thinking process.
     * Each step has a 'type' (thinking, tool_call, observation, response, confirmation, error).
     *
     * @param  string $userMessage  The user's command/request.
     * @return array<int, array{type: string, content: mixed}>
     */
    public function handle(string $userMessage): array {
        $steps = [];

        // Build system prompt with site context.
        $systemPrompt = $this->buildSystemPrompt();

        $this->messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userMessage],
        ];

        for ($i = 0; $i < $this->maxIterations; $i++) {
            $steps[] = [
                'type'    => 'thinking',
                'content' => sprintf('Iteration %d: Analyzing and planning...', $i + 1),
            ];

            // Call LLM with tools.
            $response = $this->llm->chat($this->messages, $this->tools->getSchemas());

            // Handle API errors.
            if (! empty($response['error'])) {
                $steps[] = [
                    'type'    => 'error',
                    'content' => $response['message'] ?? 'LLM request failed.',
                ];
                break;
            }

            // If LLM returns text content (final response or intermediate thought).
            if (! empty($response['content']) && empty($response['tool_calls'])) {
                $steps[] = [
                    'type'    => 'response',
                    'content' => $response['content'],
                ];
                break;
            }

            // If LLM wants to call tools.
            if (! empty($response['tool_calls'])) {
                // Add assistant message with tool calls to history.
                $assistantMessage = [
                    'role'       => 'assistant',
                    'content'    => $response['content'] ?? null,
                    'tool_calls' => [],
                ];

                foreach ($response['tool_calls'] as $toolCall) {
                    $assistantMessage['tool_calls'][] = [
                        'id'       => $toolCall['id'],
                        'type'     => 'function',
                        'function' => [
                            'name'      => $toolCall['name'],
                            'arguments' => wp_json_encode($toolCall['arguments']),
                        ],
                    ];

                    $steps[] = [
                        'type'    => 'tool_call',
                        'content' => [
                            'name'      => $toolCall['name'],
                            'arguments' => $toolCall['arguments'],
                        ],
                    ];

                    // Dispatch the tool.
                    $observation = $this->tools->dispatch($toolCall['name'], $toolCall['arguments']);

                    if (! empty($observation['requires_confirmation'])) {
                        // Store pending action and ask user to confirm.
                        $actionId = wp_generate_uuid4();
                        $this->pendingActions[$actionId] = [
                            'tool_name' => $toolCall['name'],
                            'params'    => $toolCall['arguments'],
                            'tool_call_id' => $toolCall['id'],
                        ];

                        $steps[] = [
                            'type'    => 'confirmation',
                            'content' => [
                                'action_id'  => $actionId,
                                'tool_name'  => $toolCall['name'],
                                'params'     => $toolCall['arguments'],
                                'message'    => $observation['message'],
                            ],
                        ];

                        // Feed a "waiting for confirmation" observation back to LLM.
                        $this->messages[] = $assistantMessage;
                        $this->messages[] = [
                            'role'         => 'tool',
                            'tool_call_id' => $toolCall['id'],
                            'content'      => wp_json_encode([
                                'status'  => 'pending_confirmation',
                                'message' => 'Action requires user confirmation. Waiting for approval.',
                            ]),
                        ];

                        // Stop the loop — wait for user confirmation.
                        return $steps;
                    }

                    // Read-only tool: add observation.
                    $steps[] = [
                        'type'    => 'observation',
                        'content' => $observation,
                    ];

                    $this->messages[] = $assistantMessage;
                    $this->messages[] = [
                        'role'         => 'tool',
                        'tool_call_id' => $toolCall['id'],
                        'content'      => wp_json_encode($observation),
                    ];

                    // Reset assistant message for next iteration.
                    $assistantMessage = [
                        'role'       => 'assistant',
                        'content'    => null,
                        'tool_calls' => [],
                    ];
                }

                continue;
            }

            // No content and no tool calls — shouldn't happen, but handle gracefully.
            $steps[] = [
                'type'    => 'response',
                'content' => 'Agent completed without a final response.',
            ];
            break;
        }

        return $steps;
    }

    /**
     * Confirm a pending action (user approved) and resume the ReAct loop.
     *
     * After executing the confirmed tool, the result is fed back into the
     * conversation and the agent continues its ReAct loop to complete
     * any remaining actions (e.g., creating 3 products in sequence).
     *
     * @param  string $actionId  The UUID of the pending action.
     * @return array<int, array{type: string, content: mixed}>
     */
    public function confirmAction(string $actionId): array {
        $action = $this->pendingActions[$actionId] ?? null;

        if (! $action) {
            return [[
                'type'    => 'error',
                'content' => 'Action not found or already processed.',
            ]];
        }

        // Execute the confirmed tool.
        $result = $this->tools->executeDirectly($action['tool_name'], $action['params']);

        // Clean up pending action.
        unset($this->pendingActions[$actionId]);

        $steps = [];
        $steps[] = [
            'type'    => 'observation',
            'content' => $result,
        ];

        // Feed the execution result back into conversation history.
        $this->messages[] = [
            'role'         => 'tool',
            'tool_call_id' => $action['tool_call_id'],
            'content'      => wp_json_encode($result),
        ];

        // Resume the ReAct loop so the agent can continue with remaining tasks.
        $remainingSteps = $this->resumeLoop();
        $steps = array_merge($steps, $remainingSteps);

        return $steps;
    }

    /**
     * Resume the ReAct loop after a confirmed action.
     *
     * Uses the existing conversation history (which now includes the
     * confirmed action's result) to let the LLM decide next steps.
     *
     * @return array<int, array{type: string, content: mixed}>
     */
    private function resumeLoop(): array {
        $steps = [];

        for ($i = 0; $i < $this->maxIterations; $i++) {
            $steps[] = [
                'type'    => 'thinking',
                'content' => sprintf('Continuing: Iteration %d...', $i + 1),
            ];

            $response = $this->llm->chat($this->messages, $this->tools->getSchemas());

            if (! empty($response['error'])) {
                $steps[] = [
                    'type'    => 'error',
                    'content' => $response['message'] ?? 'LLM request failed.',
                ];
                break;
            }

            // Final text response — agent is done.
            if (! empty($response['content']) && empty($response['tool_calls'])) {
                $steps[] = [
                    'type'    => 'response',
                    'content' => $response['content'],
                ];
                break;
            }

            // Tool calls — same logic as handle().
            if (! empty($response['tool_calls'])) {
                $assistantMessage = [
                    'role'       => 'assistant',
                    'content'    => $response['content'] ?? null,
                    'tool_calls' => [],
                ];

                foreach ($response['tool_calls'] as $toolCall) {
                    $assistantMessage['tool_calls'][] = [
                        'id'       => $toolCall['id'],
                        'type'     => 'function',
                        'function' => [
                            'name'      => $toolCall['name'],
                            'arguments' => wp_json_encode($toolCall['arguments']),
                        ],
                    ];

                    $steps[] = [
                        'type'    => 'tool_call',
                        'content' => [
                            'name'      => $toolCall['name'],
                            'arguments' => $toolCall['arguments'],
                        ],
                    ];

                    $observation = $this->tools->dispatch($toolCall['name'], $toolCall['arguments']);

                    if (! empty($observation['requires_confirmation'])) {
                        $actionId = wp_generate_uuid4();
                        $this->pendingActions[$actionId] = [
                            'tool_name'    => $toolCall['name'],
                            'params'       => $toolCall['arguments'],
                            'tool_call_id' => $toolCall['id'],
                        ];

                        $steps[] = [
                            'type'    => 'confirmation',
                            'content' => [
                                'action_id'  => $actionId,
                                'tool_name'  => $toolCall['name'],
                                'params'     => $toolCall['arguments'],
                                'message'    => $observation['message'],
                            ],
                        ];

                        $this->messages[] = $assistantMessage;
                        $this->messages[] = [
                            'role'         => 'tool',
                            'tool_call_id' => $toolCall['id'],
                            'content'      => wp_json_encode([
                                'status'  => 'pending_confirmation',
                                'message' => 'Action requires user confirmation. Waiting for approval.',
                            ]),
                        ];

                        // Pause again — wait for next confirmation.
                        return $steps;
                    }

                    $steps[] = [
                        'type'    => 'observation',
                        'content' => $observation,
                    ];

                    $this->messages[] = $assistantMessage;
                    $this->messages[] = [
                        'role'         => 'tool',
                        'tool_call_id' => $toolCall['id'],
                        'content'      => wp_json_encode($observation),
                    ];

                    $assistantMessage = [
                        'role'       => 'assistant',
                        'content'    => null,
                        'tool_calls' => [],
                    ];
                }

                continue;
            }

            // No content, no tool calls.
            $steps[] = [
                'type'    => 'response',
                'content' => 'Agent completed without a final response.',
            ];
            break;
        }

        return $steps;
    }

    /**
     * Reject a pending action.
     */
    public function rejectAction(string $actionId): array {
        if (isset($this->pendingActions[$actionId])) {
            unset($this->pendingActions[$actionId]);
        }

        return [
            'type'    => 'response',
            'content' => 'Action rejected by user.',
        ];
    }

    /**
     * Get pending actions for serialization.
     */
    public function getPendingActions(): array {
        return $this->pendingActions;
    }

    /**
     * Set pending actions (from session restore).
     */
    public function setPendingActions(array $actions): void {
        $this->pendingActions = $actions;
    }

    /**
     * Set conversation messages (from session restore).
     */
    public function setMessages(array $messages): void {
        $this->messages = $messages;
    }

    /**
     * Get conversation messages (for session save).
     */
    public function getMessages(): array {
        return $this->messages;
    }

    /**
     * Build the system prompt with site context and agent instructions.
     */
    private function buildSystemPrompt(): string {
        $siteContext = $this->context->getSnapshot();

        $wooSection = '';
        if (class_exists('WooCommerce')) {
            $wooSection = <<<'WOO'

## WooCommerce Capabilities
9. **Manage products** (create, update, delete, list, view details) using `woo_product_manager`
10. **Manage product categories** (list, create, delete) using `woo_product_manager` with actions: `list_categories`, `create_category`, `delete_category`
11. **Inspect orders** (list, view details, update status, revenue statistics) using `woo_order_inspector`
12. **Inspect customers** (list, search, view details, customer stats) using `woo_customer_inspector`

## WooCommerce Rules
1. When creating products, always set status to "draft" unless explicitly told to publish.
2. For order status updates, always confirm with the user first.
3. Prices should be numeric values without currency symbols (e.g. "250000" not "250.000₫").
4. **CRITICAL: Product categories (WooCommerce) are COMPLETELY SEPARATE from post categories (WordPress).** 
   - To list/create/delete PRODUCT categories: use `woo_product_manager` with `list_categories`/`create_category`/`delete_category`.
   - To list/create/delete POST categories: use `wp_taxonomy_manager` or `wp_system_inspector`.
   - NEVER use `wp_system_inspector` or `wp_taxonomy_manager` for WooCommerce product categories.
5. Always list existing product categories (`list_categories`) before creating products.
6. When user asks to create a product category, use `woo_product_manager` with action `create_category` and param `category_name`.
7. When listing orders, default to showing recent orders across all statuses.
8. When user asks to create products for a category, first create the category, then create products using the returned category ID.
WOO;
        }

        return <<<PROMPT
You are Open Claw, an AI Agent embedded in a WordPress website. You are an action-oriented agent — your job is to EXECUTE real WordPress operations, not just provide text answers.

## Your Capabilities
You have access to tools that let you:
1. **Create/Update posts** using `wp_content_manager`
2. **Inspect the system** (categories, tags, plugins, site info) using `wp_system_inspector`
3. **Research the web** for up-to-date information using `web_research_tool`
4. **Manage categories & tags** (create, update, delete) using `wp_taxonomy_manager`
5. **Manage media** (upload images from URL, set featured images) using `wp_media_manager`
6. **Manage pages** (create, update, list, delete) using `wp_page_manager`
7. **Inspect users** (list, details, count by role) using `wp_user_inspector`
8. **Read analytics** (post stats, comment stats, content summary) using `wp_analytics_reader`
{$wooSection}

## Rules
1. Always inspect the system FIRST to understand the current state before making changes.
2. When creating posts, ALWAYS check available categories first to use the correct Category ID.
3. If a category doesn't exist, INFORM the user and suggest creating it or using an alternative.
4. Default post status is "draft" unless the user explicitly says to publish.
5. Write content in the same language as the user's request.
6. Return observations as JSON so the system can parse your results.
7. If a tool returns an error, analyze it and try a different approach or ask the user for help.

## Response Format
- When thinking, explain your reasoning briefly.
- When acting, call the appropriate tool with correct parameters.
- When done, summarize what you accomplished.

{$siteContext}
PROMPT;
    }
}
