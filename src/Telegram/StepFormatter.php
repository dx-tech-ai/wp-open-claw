<?php

declare(strict_types=1);

namespace OpenClaw\Telegram;

defined('ABSPATH') || exit;

/**
 * Format Kernel steps for Telegram messages.
 *
 * Converts ReAct loop steps into Telegram-friendly Markdown text.
 */
class StepFormatter {

    private const MAX_MESSAGE_LENGTH = 4096;

    /**
     * Format an array of Kernel steps into Telegram messages.
     *
     * Returns an array of messages to send (may be split if too long).
     *
     * @param array $steps Kernel steps from handle() or confirmAction().
     * @return array Array of formatted message strings.
     */
    public static function format(array $steps): array {
        $parts = [];

        foreach ($steps as $step) {
            $type = $step['type'] ?? '';

            switch ($type) {
                case 'thinking':
                    // Skip verbose thinking — just note the iteration.
                    break;

                case 'tool_call':
                    $name = $step['tool'] ?? 'unknown';
                    if ($name !== 'unknown') {
                        $parts[] = "🔧 _{$name}_";
                    }
                    break;

                case 'observation':
                    // Hide raw observation data — too verbose for Telegram.
                    break;

                case 'answer':
                    $content = self::toString($step['content'] ?? '');
                    if (! empty($content)) {
                        $parts[] = $content;
                    }
                    break;

                case 'confirmation':
                    // Skip — handled by TelegramController via inline keyboard.
                    break;

                case 'error':
                    $content = self::toString($step['content'] ?? $step['message'] ?? 'Unknown error');
                    $parts[] = "❌ " . $content;
                    break;

                case 'rejected':
                    $parts[] = "⛔ Hành động đã bị từ chối.";
                    break;

                default:
                    if (! empty($step['content'])) {
                        $parts[] = self::toString($step['content']);
                    }
                    break;
            }
        }

        if (empty($parts)) {
            return [];
        }

        // Join and split into chunks respecting the Telegram message limit.
        return self::splitMessages(implode("\n\n", $parts));
    }

    /**
     * Check if any step in the array is a confirmation step.
     *
     * @param array $steps
     * @return array|null Confirmation step data or null.
     */
    public static function findConfirmation(array $steps): ?array {
        foreach ($steps as $step) {
            if (($step['type'] ?? '') === 'confirmation') {
                return $step;
            }
        }
        return null;
    }

    /**
     * Summarize params into a compact key=value format.
     */
    private static function summarizeParams(array $params): string {
        $lines = [];
        foreach ($params as $key => $value) {
            if (is_array($value)) {
                $value = wp_json_encode($value, JSON_UNESCAPED_UNICODE);
            }
            $value   = (string) $value;
            $value   = mb_substr($value, 0, 150);
            $lines[] = "{$key}: {$value}";
        }
        return implode("\n", $lines);
    }

    /**
     * Escape special Markdown characters for Telegram.
     */
    private static function escapeMarkdown(string $text): string {
        // In Markdown mode, we only need to escape ` and *
        // which are used for formatting. Avoid breaking our own formatting.
        return str_replace(
            ['`', '*', '_', '['],
            ['\\`', '\\*', '\\_', '\\['],
            $text
        );
    }

    /**
     * Ensure a value is a string.
     */
    private static function toString($value): string {
        if (is_array($value)) {
            return (string) wp_json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        }
        return (string) $value;
    }

    /**
     * Split a long message into chunks of <= 4096 characters.
     *
     * @param string $message
     * @return array
     */
    private static function splitMessages(string $message): array {
        if (mb_strlen($message) <= self::MAX_MESSAGE_LENGTH) {
            return [$message];
        }

        $chunks = [];
        while (mb_strlen($message) > 0) {
            if (mb_strlen($message) <= self::MAX_MESSAGE_LENGTH) {
                $chunks[] = $message;
                break;
            }

            // Try to split at a newline.
            $chunk = mb_substr($message, 0, self::MAX_MESSAGE_LENGTH);
            $last_newline = mb_strrpos($chunk, "\n");

            if ($last_newline !== false && $last_newline > self::MAX_MESSAGE_LENGTH * 0.5) {
                $chunks[] = mb_substr($message, 0, $last_newline);
                $message  = mb_substr($message, $last_newline + 1);
            } else {
                $chunks[] = $chunk;
                $message  = mb_substr($message, self::MAX_MESSAGE_LENGTH);
            }
        }

        return $chunks;
    }
}
