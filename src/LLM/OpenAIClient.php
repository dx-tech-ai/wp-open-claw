<?php

declare(strict_types=1);

namespace OpenClaw\LLM;

defined('ABSPATH') || exit;

use Generator;

/**
 * OpenAI API client (default provider).
 *
 * Uses wp_remote_post() — no external HTTP library needed.
 */
class OpenAIClient implements ClientInterface {

    use ErrorMapper;

    private const API_URL = 'https://api.openai.com/v1/chat/completions';

    private string $apiKey;
    private string $model;

    public function __construct(?string $apiKey = null, ?string $model = null) {
        $settings       = \OpenClaw\Admin\Settings::get_decrypted_settings();
        $this->apiKey   = $apiKey ?? ($settings['openai_api_key'] ?? '');
        $this->model    = $model ?? ($settings['openai_model'] ?? 'gpt-4o');
    }

    /**
     * @inheritDoc
     */
    public function chat(array $messages, array $tools = []): array {
        $body = [
            'model'      => $this->model,
            'messages'   => $messages,
            'max_tokens' => 8192,
        ];

        if (! empty($tools)) {
            $body['tools']       = $tools;
            $body['tool_choice'] = 'auto';
        }

        $response = wp_remote_post(self::API_URL, [
            'timeout' => 120,
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $this->apiKey,
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
            $rawMsg = $data['error']['message'] ?? '';
            return [
                'error'   => true,
                'message' => $this->mapApiError($status, $rawMsg),
                'error_code' => $status,
            ];
        }

        $choice = $data['choices'][0] ?? [];

        return $this->parseChoice($choice);
    }

    /**
     * @inheritDoc
     */
    public function stream(array $messages, array $tools = []): Generator {
        $body = [
            'model'    => $this->model,
            'messages' => $messages,
            'stream'   => true,
        ];

        if (! empty($tools)) {
            $body['tools']       = $tools;
            $body['tool_choice'] = 'auto';
        }

        // Use cURL directly for streaming (wp_remote_post doesn't support SSE).
        $ch = curl_init(self::API_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ],
            CURLOPT_POSTFIELDS     => wp_json_encode($body),
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $tool_calls    = [];
        $content       = '';
        $finish_reason = null;

        // Collect full response, then parse.
        $fullResponse = '';
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $chunk) use (&$fullResponse) {
            $fullResponse .= $chunk;
            return strlen($chunk);
        });

        curl_exec($ch);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            yield [
                'type'    => 'error',
                'content' => "cURL error: {$curlError}",
            ];
            return;
        }

        // Parse SSE lines.
        $lines = explode("\n", $fullResponse);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line === 'data: [DONE]') {
                continue;
            }
            if (substr($line, 0, 6) === 'data: ') {
                $json = json_decode(substr($line, 6), true);
                if (! $json) {
                    continue;
                }

                $delta = $json['choices'][0]['delta'] ?? [];
                $finish_reason = $json['choices'][0]['finish_reason'] ?? $finish_reason;

                // Content delta.
                if (isset($delta['content'])) {
                    $content .= $delta['content'];
                    yield [
                        'type'    => 'content',
                        'content' => $delta['content'],
                    ];
                }

                // Tool call deltas.
                if (isset($delta['tool_calls'])) {
                    foreach ($delta['tool_calls'] as $tc) {
                        $idx = $tc['index'];
                        if (! isset($tool_calls[$idx])) {
                            $tool_calls[$idx] = [
                                'id'       => $tc['id'] ?? '',
                                'name'     => $tc['function']['name'] ?? '',
                                'arguments'=> '',
                            ];
                        }
                        if (isset($tc['function']['arguments'])) {
                            $tool_calls[$idx]['arguments'] .= $tc['function']['arguments'];
                        }
                    }
                }
            }
        }

        // Yield complete tool calls at the end.
        if (! empty($tool_calls)) {
            foreach ($tool_calls as $tc) {
                yield [
                    'type'      => 'tool_call',
                    'id'        => $tc['id'],
                    'name'      => $tc['name'],
                    'arguments' => json_decode($tc['arguments'], true) ?? [],
                ];
            }
        }

        yield [
            'type'          => 'done',
            'finish_reason' => $finish_reason,
            'full_content'  => $content,
        ];
    }

    /**
     * Parse a single choice from the API response.
     */
    private function parseChoice(array $choice): array {
        $message = $choice['message'] ?? [];
        $result  = [
            'content'       => $message['content'] ?? null,
            'tool_calls'    => [],
            'finish_reason' => $choice['finish_reason'] ?? null,
        ];

        if (! empty($message['tool_calls'])) {
            foreach ($message['tool_calls'] as $tc) {
                $result['tool_calls'][] = [
                    'id'        => $tc['id'],
                    'name'      => $tc['function']['name'],
                    'arguments' => json_decode($tc['function']['arguments'], true) ?? [],
                ];
            }
        }

        return $result;
    }
}
