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

    private const MAX_MESSAGE_LENGTH = 10000;
    private const MAX_HISTORY_MESSAGES = 50;

    /** @var array Conversation message history for this session. */
    private array $messages = [];

    /** @var array Pending actions awaiting user confirmation. */
    private array $pendingActions = [];

    public function __construct() {
        $settings = \OpenClaw\Admin\Settings::get_decrypted_settings();

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

        if (mb_strlen($userMessage) > self::MAX_MESSAGE_LENGTH) {
            return [[
                'type'    => 'error',
                'content' => sprintf('Message too long. Maximum %d characters allowed.', self::MAX_MESSAGE_LENGTH),
            ]];
        }

        // Build system prompt with fresh site context.
        $systemPrompt = $this->buildSystemPrompt();

        if (! empty($this->messages)) {
            // Continuing an existing session — update system prompt and append new message.
            $this->messages[0] = ['role' => 'system', 'content' => $systemPrompt];
            $this->messages[]  = ['role' => 'user', 'content' => $userMessage];
        } else {
            // New session — initialize fresh.
            $this->messages = [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userMessage],
            ];
        }

        // Trim old messages to prevent token overflow on long sessions.
        $this->trimMessages();

        $emptyRetries = 0;

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

            // Detect truncated response (hit token limit).
            if (($response['finish_reason'] ?? '') === 'length') {
                $steps[] = [
                    'type'    => 'thinking',
                    'content' => 'Response was truncated due to token limit. Requesting continuation...',
                ];

                // Add partial content if any.
                if (! empty($response['content'])) {
                    $this->messages[] = [
                        'role'    => 'assistant',
                        'content' => $response['content'],
                    ];
                }

                // Ask LLM to continue/summarize.
                $this->messages[] = [
                    'role'    => 'user',
                    'content' => 'Your previous response was cut off due to length limits. Please summarize what you have accomplished so far and provide your final answer concisely. If you still need to call a tool, do so now.',
                ];
                continue;
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
                $emptyRetries = 0; // Reset empty counter on successful tool call.

                $result = $this->processToolCalls($response, $steps);
                if ($result === 'pause') {
                    return $steps;
                }
                continue;
            }

            // No content and no tool calls — attempt recovery.
            $emptyRetries++;
            if ($emptyRetries <= 1) {
                $steps[] = [
                    'type'    => 'thinking',
                    'content' => 'LLM returned empty response. Attempting recovery...',
                ];
                $this->messages[] = [
                    'role'    => 'user',
                    'content' => 'You stopped without providing a final answer. Please review the conversation and provide your complete response now. If the task is too complex, explain what you can do and suggest how to break it into smaller steps.',
                ];
                continue;
            }

            // Recovery failed — provide friendly error.
            $steps[] = [
                'type'    => 'response',
                'content' => '⚠️ Xin lỗi, tôi gặp khó khăn khi xử lý yêu cầu này. '
                    . 'Yêu cầu có thể quá phức tạp để xử lý trong một lần. '
                    . "Bạn có thể thử:\n"
                    . "1. **Chia nhỏ yêu cầu** — ví dụ: tạo dàn ý trước, sau đó viết từng phần.\n"
                    . "2. **Đơn giản hóa** — bớt điều kiện hoặc giảm độ dài yêu cầu.\n"
                    . "3. **Thử lại** — đôi khi chạy lại sẽ cho kết quả tốt hơn.",
            ];
            break;
        }

        // Max iterations exhausted without a response.
        $lastStep = end($steps);
        if ($lastStep && $lastStep['type'] === 'thinking') {
            $steps[] = [
                'type'    => 'response',
                'content' => '⚠️ Tác vụ quá phức tạp, tôi đã xử lý qua ' . $this->maxIterations
                    . ' bước nhưng chưa hoàn thành. Hãy thử chia nhỏ yêu cầu để tôi xử lý từng phần.',
            ];
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
        $emptyRetries = 0;

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

            // Detect truncated response.
            if (($response['finish_reason'] ?? '') === 'length') {
                $steps[] = [
                    'type'    => 'thinking',
                    'content' => 'Response was truncated. Requesting continuation...',
                ];
                if (! empty($response['content'])) {
                    $this->messages[] = [
                        'role'    => 'assistant',
                        'content' => $response['content'],
                    ];
                }
                $this->messages[] = [
                    'role'    => 'user',
                    'content' => 'Your previous response was cut off due to length limits. Please summarize what you have accomplished so far and provide your final answer concisely. If you still need to call a tool, do so now.',
                ];
                continue;
            }

            // Final text response — agent is done.
            if (! empty($response['content']) && empty($response['tool_calls'])) {
                $steps[] = [
                    'type'    => 'response',
                    'content' => $response['content'],
                ];
                break;
            }

            // Tool calls.
            if (! empty($response['tool_calls'])) {
                $emptyRetries = 0;
                $result = $this->processToolCalls($response, $steps);
                if ($result === 'pause') {
                    return $steps;
                }
                continue;
            }

            // No content, no tool calls — attempt recovery.
            $emptyRetries++;
            if ($emptyRetries <= 1) {
                $steps[] = [
                    'type'    => 'thinking',
                    'content' => 'LLM returned empty response. Attempting recovery...',
                ];
                $this->messages[] = [
                    'role'    => 'user',
                    'content' => 'You stopped without providing a final answer. Please review the conversation and provide your complete response now.',
                ];
                continue;
            }

            $steps[] = [
                'type'    => 'response',
                'content' => '⚠️ Xin lỗi, tôi gặp khó khăn khi hoàn thành yêu cầu này. '
                    . 'Hãy thử chia nhỏ yêu cầu hoặc thử lại.',
            ];
            break;
        }

        return $steps;
    }

    /**
     * Process tool calls from an LLM response.
     *
     * Shared logic between handle() and resumeLoop().
     *
     * @param  array $response LLM response with tool_calls.
     * @param  array &$steps   Steps array (passed by reference to append).
     * @return string|null     'pause' if waiting for confirmation, null otherwise.
     */
    private function processToolCalls(array $response, array &$steps): ?string {
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

                return 'pause';
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

            // Reset for next tool call in this batch.
            $assistantMessage = [
                'role'       => 'assistant',
                'content'    => null,
                'tool_calls' => [],
            ];
        }

        return null;
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
     * Trim conversation history to prevent token overflow.
     *
     * Keeps the system prompt (index 0) and the most recent messages.
     * This ensures long sessions don't exceed LLM context limits.
     */
    private function trimMessages(): void {
        $count = count($this->messages);
        if ($count <= self::MAX_HISTORY_MESSAGES) {
            return;
        }

        // Keep system prompt + last (MAX - 10) messages for safety margin.
        $keepCount   = self::MAX_HISTORY_MESSAGES - 10;
        $systemMsg   = $this->messages[0];
        $recentMsgs  = array_slice($this->messages, -$keepCount);

        $this->messages = array_merge([$systemMsg], $recentMsgs);
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

## CRITICAL: Action Execution Rules
**YOU ARE AN ACTION AGENT, NOT A CHATBOT.**

8. **NEVER just write content as text in the chat. ALWAYS use the appropriate tool to create/publish it on WordPress.**
   - If the user asks to "write a blog post" → you MUST call `wp_content_manager` to create the post, not just output the text.
   - If the user asks to "create a product" → you MUST call `woo_product_manager`, not just describe it.
   - If the user asks to "create a page" → you MUST call `wp_page_manager`.

9. **Action intent keywords** — if the user's request contains ANY of these words, you MUST call the corresponding tool to complete the action:
   - Vietnamese: viết bài, tạo bài, đăng bài, xuất bản, tạo sản phẩm, tạo trang, cập nhật, xóa, sửa
   - English: write, create, post, publish, update, delete, edit, make, add

10. **Complete the full workflow:**
    - Step 1: Inspect system state (categories, existing content)
    - Step 2: Generate content mentally (do NOT output to chat)
    - Step 3: Call the tool with the complete content as parameters
    - Step 4: Confirm the result to user with a summary

11. **DO NOT stop after generating text.** If you have written content, you are NOT done until you have called a tool to save/publish it.

## Response Format
- When thinking, explain your reasoning briefly.
- When acting, call the appropriate tool with correct parameters.
- When done, summarize what you accomplished with key details (post ID, URL, status).
- **IMPORTANT: Your final response should be a summary of ACTIONS TAKEN, not the content itself.**

{$siteContext}
PROMPT;
    }
}
