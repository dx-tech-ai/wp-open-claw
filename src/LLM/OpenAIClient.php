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
        // Use non-streaming via wp_remote_post (wp_remote_post doesn't support SSE).
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
