<?php

declare(strict_types=1);

namespace OpenClaw\LLM;

use Generator;

/**
 * Google Gemini (AI Studio) API client.
 *
 * Uses the Gemini API with function calling support.
 * Free tier via AI Studio: https://aistudio.google.com/
 */
class GeminiClient implements ClientInterface {

    private const API_BASE = 'https://generativelanguage.googleapis.com/v1beta/models/';

    private string $apiKey;
    private string $model;

    public function __construct(?string $apiKey = null, ?string $model = null) {
        $settings       = get_option('wpoc_settings', []);
        $this->apiKey   = $apiKey ?? ($settings['gemini_api_key'] ?? '');
        $this->model    = $model ?? ($settings['gemini_model'] ?? 'gemini-2.5-flash');
    }

    /**
     * @inheritDoc
     */
    public function chat(array $messages, array $tools = []): array {
        $url = self::API_BASE . $this->model . ':generateContent?key=' . $this->apiKey;

        $body = [
            'contents'         => $this->mapMessages($messages),
            'generationConfig' => [
                'temperature'  => 0.7,
                'maxOutputTokens' => 4096,
            ],
        ];

        // Extract system instruction.
        $systemInstruction = $this->extractSystemInstruction($messages);
        if ($systemInstruction) {
            $body['systemInstruction'] = [
                'parts' => [['text' => $systemInstruction]],
            ];
        }

        // Map tools to Gemini format.
        if (! empty($tools)) {
            $body['tools'] = [$this->mapToolsToGemini($tools)];
        }

        $response = wp_remote_post($url, [
            'timeout' => 120,
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => wp_json_encode($body),
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
            $errMsg = $data['error']['message'] ?? "API error (HTTP {$status})";
            return [
                'error'   => true,
                'message' => $errMsg,
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
            yield ['type' => 'error', 'content' => $result['message']];
            return;
        }

        if ($result['content']) {
            yield ['type' => 'content', 'content' => $result['content']];
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
     * Extract system message from the messages array.
     */
    private function extractSystemInstruction(array $messages): ?string {
        $system = '';
        foreach ($messages as $msg) {
            if ($msg['role'] === 'system') {
                $system .= $msg['content'] . "\n";
            }
        }
        return $system ? trim($system) : null;
    }

    /**
     * Map OpenAI-style messages to Gemini format.
     */
    private function mapMessages(array $messages): array {
        $contents = [];

        foreach ($messages as $msg) {
            // Skip system messages (handled separately).
            if ($msg['role'] === 'system') {
                continue;
            }

            // Map tool results.
            if ($msg['role'] === 'tool') {
                $contents[] = [
                    'role'  => 'function',
                    'parts' => [
                        [
                            'functionResponse' => [
                                'name'     => $msg['tool_name'] ?? 'tool_response',
                                'response' => [
                                    'content' => is_string($msg['content']) ? $msg['content'] : wp_json_encode($msg['content']),
                                ],
                            ],
                        ],
                    ],
                ];
                continue;
            }

            // Map assistant messages with tool calls.
            if ($msg['role'] === 'assistant' && ! empty($msg['tool_calls'])) {
                $parts = [];
                if (! empty($msg['content'])) {
                    $parts[] = ['text' => $msg['content']];
                }
                foreach ($msg['tool_calls'] as $tc) {
                    $args = $tc['arguments'] ?? [];
                    if (isset($tc['function']['arguments'])) {
                        $args = json_decode($tc['function']['arguments'], true) ?? [];
                    }
                    $name = $tc['name'] ?? $tc['function']['name'] ?? 'unknown';
                    $parts[] = [
                        'functionCall' => [
                            'name' => $name,
                            'args' => (object) $args,
                        ],
                    ];
                }
                $contents[] = ['role' => 'model', 'parts' => $parts];
                continue;
            }

            // Regular user/assistant messages.
            $role = $msg['role'] === 'assistant' ? 'model' : 'user';
            $contents[] = [
                'role'  => $role,
                'parts' => [['text' => $msg['content'] ?? '']],
            ];
        }

        return $contents;
    }

    /**
     * Map OpenAI-style tools to Gemini format.
     */
    private function mapToolsToGemini(array $tools): array {
        $declarations = [];

        foreach ($tools as $tool) {
            if ($tool['type'] !== 'function') {
                continue;
            }
            $declarations[] = [
                'name'        => $tool['function']['name'],
                'description' => $tool['function']['description'],
                'parameters'  => $this->convertSchema($tool['function']['parameters']),
            ];
        }

        return ['functionDeclarations' => $declarations];
    }

    /**
     * Convert JSON Schema to Gemini-compatible format.
     * Gemini doesn't support some JSON Schema features, so we simplify.
     */
    private function convertSchema(array $schema): array {
        $result = ['type' => strtoupper($schema['type'] ?? 'OBJECT')];

        if (isset($schema['properties'])) {
            $props = [];
            foreach ($schema['properties'] as $name => $prop) {
                $p = ['type' => strtoupper($prop['type'] ?? 'STRING')];
                if (isset($prop['description'])) {
                    $p['description'] = $prop['description'];
                }
                if (isset($prop['enum'])) {
                    $p['enum'] = $prop['enum'];
                }
                if (isset($prop['items'])) {
                    $p['items'] = ['type' => strtoupper($prop['items']['type'] ?? 'STRING')];
                }
                $props[$name] = $p;
            }
            $result['properties'] = $props;
        }

        if (isset($schema['required'])) {
            $result['required'] = $schema['required'];
        }

        return $result;
    }

    /**
     * Parse Gemini response to our common format.
     */
    private function parseResponse(array $data): array {
        $content    = '';
        $toolCalls  = [];

        $candidate = $data['candidates'][0] ?? [];
        $parts     = $candidate['content']['parts'] ?? [];

        foreach ($parts as $part) {
            if (isset($part['text'])) {
                $content .= $part['text'];
            }
            if (isset($part['functionCall'])) {
                $toolCalls[] = [
                    'id'        => 'gemini_' . wp_generate_uuid4(),
                    'name'      => $part['functionCall']['name'],
                    'arguments' => (array) ($part['functionCall']['args'] ?? []),
                ];
            }
        }

        $finishReason = $candidate['finishReason'] ?? null;
        $mappedReason = match ($finishReason) {
            'STOP'  => 'stop',
            'MAX_TOKENS' => 'length',
            default => $finishReason,
        };

        // If there are tool calls, set finish_reason to 'tool_calls' for Kernel compatibility.
        if (! empty($toolCalls)) {
            $mappedReason = 'tool_calls';
        }

        return [
            'content'       => $content ?: null,
            'tool_calls'    => $toolCalls,
            'finish_reason' => $mappedReason,
        ];
    }
}
