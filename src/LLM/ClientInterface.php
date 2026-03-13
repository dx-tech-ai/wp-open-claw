<?php

declare(strict_types=1);

namespace OpenClaw\LLM;

use Generator;

/**
 * Contract for LLM API clients.
 */
interface ClientInterface {

    /**
     * Send a chat completion request (non-streaming).
     *
     * @param  array $messages  Conversation messages.
     * @param  array $tools     Tool schemas for function calling.
     * @return array            Parsed response with content and/or tool_calls.
     */
    public function chat(array $messages, array $tools = []): array;

    /**
     * Send a streaming chat completion request.
     *
     * Yields partial response chunks for real-time UI updates.
     *
     * @param  array $messages  Conversation messages.
     * @param  array $tools     Tool schemas for function calling.
     * @return Generator        Yields arrays with partial content/tool_calls.
     */
    public function stream(array $messages, array $tools = []): Generator;
}
