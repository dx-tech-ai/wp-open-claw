<?php

declare(strict_types=1);

namespace OpenClaw\LLM;

defined('ABSPATH') || exit;

use Generator;

/**
 * Cloudflare Workers AI client.
 *
 * Uses the OpenAI-compatible endpoint for chat completions.
 * Free tier: https://developers.cloudflare.com/workers-ai/
 */
class CloudflareClient implements ClientInterface {

    use ErrorMapper;

    private string $accountId;
    private string $apiToken;
    private string $model;

    public function __construct(?string $apiToken = null, ?string $model = null) {
        $settings          = \OpenClaw\Admin\Settings::get_decrypted_settings();
        $this->accountId   = $settings['cloudflare_account_id'] ?? '';
        $this->apiToken    = $apiToken ?? ($settings['cloudflare_api_token'] ?? '');
        $this->model       = $model ?? ($settings['cloudflare_model'] ?? '@cf/qwen/qwen2.5-72b-instruct');
    }

    /**
     * @inheritDoc
     */
    public function chat(array $messages, array $tools = []): array {
        $url = sprintf(
            'https://api.cloudflare.com/client/v4/accounts/%s/ai/v1/chat/completions',
            $this->accountId
        );

        $body = [
            'model'      => $this->model,
            'messages'   => $messages,
            'max_tokens' => 8192,
        ];

        if (! empty($tools)) {
            $body['tools']       = $tools;
            $body['tool_choice'] = 'auto';
        }

        $response = wp_remote_post($url, [
            'timeout' => 120,
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $this->apiToken,
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
            $rawMsg = $data['errors'][0]['message'] ?? $data['error']['message'] ?? '';
            return [
                'error'      => true,
                'message'    => $this->mapApiError($status, $rawMsg),
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
                    'id'        => $tc['id'] ?? ('cf_' . wp_generate_uuid4()),
                    'name'      => $tc['function']['name'],
                    'arguments' => json_decode($tc['function']['arguments'], true) ?? [],
                ];
            }
        }

        if (! empty($result['tool_calls']) && $result['finish_reason'] !== 'tool_calls') {
            $result['finish_reason'] = 'tool_calls';
        }

        return $result;
    }
}
