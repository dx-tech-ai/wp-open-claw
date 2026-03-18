<?php

declare(strict_types=1);

namespace OpenClaw\LLM;

defined('ABSPATH') || exit;

/**
 * Trait for mapping raw API errors to user-friendly messages.
 *
 * Used by all LLM client implementations to provide clear,
 * non-technical error messages to end users.
 */
trait ErrorMapper {

    /**
     * Map an HTTP status code and raw error message to a user-friendly message.
     *
     * @param  int    $httpStatus HTTP status code from the API.
     * @param  string $rawMessage Raw error message from the API response.
     * @return string             User-friendly error message.
     */
    private function mapApiError(int $httpStatus, string $rawMessage): string {
        // Quota / Rate limit errors (HTTP 429).
        if ($httpStatus === 429 || stripos($rawMessage, 'quota') !== false || stripos($rawMessage, 'rate limit') !== false) {
            return '⚠️ You have exceeded your free API quota. '
                . 'Please wait a few minutes and try again, or configure a new API Key in Settings.';
        }

        // Authentication errors (HTTP 401, 403).
        if ($httpStatus === 401 || $httpStatus === 403
            || stripos($rawMessage, 'api key') !== false
            || stripos($rawMessage, 'unauthorized') !== false
            || stripos($rawMessage, 'permission') !== false
        ) {
            return '🔑 Your API Key is invalid or has expired. '
                . 'Please check and update your API Key in Settings.';
        }

        // Model not found / Invalid model.
        if (stripos($rawMessage, 'model') !== false && (
            stripos($rawMessage, 'not found') !== false
            || stripos($rawMessage, 'does not exist') !== false
        )) {
            return '⚠️ The selected AI model does not exist or is unavailable. '
                . 'Please verify the model name in Settings.';
        }

        // Content safety / moderation.
        if (stripos($rawMessage, 'safety') !== false
            || stripos($rawMessage, 'blocked') !== false
            || stripos($rawMessage, 'content filter') !== false
            || stripos($rawMessage, 'moderation') !== false
        ) {
            return '🛡️ Your request was blocked by the AI safety filter. '
                . 'Please try again with different content.';
        }

        // Request too large (HTTP 413).
        if ($httpStatus === 413
            || stripos($rawMessage, 'too large') !== false
            || stripos($rawMessage, 'maximum context') !== false
        ) {
            return '📦 The request is too large and exceeds the AI processing limit. '
                . 'Try breaking your request into smaller parts or shortening the content.';
        }

        // Server errors (5xx).
        if ($httpStatus >= 500) {
            return '🔧 The AI server is experiencing a temporary issue. '
                . 'Please try again in a few minutes.';
        }

        // Bad request (HTTP 400) — generic.
        if ($httpStatus === 400) {
            return '⚠️ Invalid request. '
                . 'Please try again with different content or check your plugin Settings.';
        }

        // Fallback — still show a clean message with minimal technical info.
        return '❌ An error occurred while communicating with the AI (HTTP ' . $httpStatus . '). '
            . 'Please try again later or check your Settings.';
    }
}
