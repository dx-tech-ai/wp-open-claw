<?php

declare(strict_types=1);

namespace OpenClaw\LLM;

use Generator;

/**
 * Anthropic (Claude) API client.
 *
 * Maps Anthropic's tool_use format to our common ClientInterface.
 */
class AnthropicClient implements ClientInterface {

    private const API_URL = 'https://api.anthropic.com/v1/messages';

    private string $apiKey;
    private string $model;

    public function __construct(?string $apiKey = null, ?string $model = null) {
        $settings       = get_option('wpoc_settings', []);
        $this->apiKey   = $apiKey ?? ($settings['anthropic_api_key'] ?? '');
        $this->model    = $model ?? ($settings['anthropic_model'] ?? 'claude-sonnet-4-20250514');
    }

    /**
     * @inheritDoc
     */
    public function chat(array $messages, array $tools = []): array {
        // Separate system message from conversation.
        $system = '';
        $conversationMessages = [];

        foreach ($messages as $msg) {
            if ($msg['role'] === 'system') {
                $system .= $msg['content'] . "\n";
            } else {
                $conversationMessages[] = $this->mapMessage($msg);
            }
        }

        $body = [
            'model'      => $this->model,
            'max_tokens' => 4096,
            'messages'   => $conversationMessages,
        ];

        if ($system !== '') {
            $body['system'] = trim($system);
        }

        if (! empty($tools)) {
            $body['tools'] = $this->mapToolsToAnthropic($tools);
        }

        $response = wp_remote_post(self::API_URL, [
            'timeout' => 120,
            'headers' => [
                'Content-Type'      => 'application/json',
                'x-api-key'         => $this->apiKey,
                'anthropic-version' => '2023-06-01',
            ],
            'body' => wp_json_encode($body),
        ]);

        if (is_wp_error($response)) {
            return [
                'error'   => true,
                'message' => $response->get_error_message(),
            ];
        }

        $status = wp_remote_retrieve_response_code($response);
        $data   = json_decode(wp_remote_retrieve_body($response), true);

        if ($status !== 200) {
            return [
                'error'   => true,
                'message' => $data['error']['message'] ?? "API error (HTTP {$status})",
            ];
        }

        return $this->parseResponse($data);
    }

    /**
     * @inheritDoc
     */
    public function stream(array $messages, array $tools = []): Generator {
        // For MVP, use non-streaming and yield the full response.
        $result = $this->chat($messages, $tools);

        if (isset($result['error'])) {
            yield [
                'type'    => 'error',
                'content' => $result['message'],
            ];
            return;
        }

        if ($result['content']) {
            yield [
                'type'    => 'content',
                'content' => $result['content'],
            ];
        }

        foreach ($result['tool_calls'] as $tc) {
            yield [
                'type'      => 'tool_call',
                'id'        => $tc['id'],
                'name'      => $tc['name'],
                'arguments' => $tc['arguments'],
            ];
        }

        yield [
            'type'          => 'done',
            'finish_reason' => $result['finish_reason'],
            'full_content'  => $result['content'] ?? '',
        ];
    }

    /**
     * Map OpenAI-style tools to Anthropic format.
     */
    private function mapToolsToAnthropic(array $tools): array {
        $mapped = [];

        foreach ($tools as $tool) {
            if ($tool['type'] !== 'function') {
                continue;
            }
            $mapped[] = [
                'name'         => $tool['function']['name'],
                'description'  => $tool['function']['description'],
                'input_schema' => $tool['function']['parameters'],
            ];
        }

        return $mapped;
    }

    /**
     * Map message format for Anthropic (handle tool results).
     */
    private function mapMessage(array $msg): array {
        if ($msg['role'] === 'tool') {
            return [
                'role'    => 'user',
                'content' => [
                    [
                        'type'       => 'tool_result',
                        'tool_use_id'=> $msg['tool_call_id'] ?? '',
                        'content'    => is_string($msg['content']) ? $msg['content'] : wp_json_encode($msg['content']),
                    ],
                ],
            ];
        }

        if ($msg['role'] === 'assistant' && isset($msg['tool_calls'])) {
            $content = [];
            if (! empty($msg['content'])) {
                $content[] = ['type' => 'text', 'text' => $msg['content']];
            }
            foreach ($msg['tool_calls'] as $tc) {
                $content[] = [
                    'type'  => 'tool_use',
                    'id'    => $tc['id'],
                    'name'  => $tc['name'] ?? $tc['function']['name'] ?? '',
                    'input' => $tc['arguments'] ?? json_decode($tc['function']['arguments'] ?? '{}', true),
                ];
            }
            return ['role' => 'assistant', 'content' => $content];
        }

        return [
            'role'    => $msg['role'],
            'content' => $msg['content'],
        ];
    }

    /**
     * Parse Anthropic response to our common format.
     */
    private function parseResponse(array $data): array {
        $content    = '';
        $toolCalls  = [];

        foreach ($data['content'] ?? [] as $block) {
            if ($block['type'] === 'text') {
                $content .= $block['text'];
            } elseif ($block['type'] === 'tool_use') {
                $toolCalls[] = [
                    'id'        => $block['id'],
                    'name'      => $block['name'],
                    'arguments' => $block['input'] ?? [],
                ];
            }
        }

        $stopReason = $data['stop_reason'] ?? null;

        return [
            'content'       => $content ?: null,
            'tool_calls'    => $toolCalls,
            'finish_reason' => $stopReason === 'tool_use' ? 'tool_calls' : $stopReason,
        ];
    }
}
