<?php

declare(strict_types=1);

namespace OpenClaw\Zalo;

defined('ABSPATH') || exit;

/**
 * Format Kernel steps for Zalo message output.
 *
 * Zalo supports only plain text — no Markdown, no embeds.
 */
class StepFormatter {

    /**
     * Build a single text block from Kernel steps.
     */
    public static function format(array $steps): string {
        $parts = [];

        foreach ($steps as $step) {
            $type = $step['type'] ?? '';

            switch ($type) {
                case 'response':
                    $text = self::toString($step['content'] ?? '');
                    if ($text !== '') {
                        $parts[] = $text;
                    }
                    break;

                case 'error':
                    $parts[] = '⚠ Lỗi: ' . self::toString($step['content'] ?? 'Unknown error');
                    break;

                case 'observation':
                    $message = self::extractMessage($step['content'] ?? null);
                    if ($message !== '') {
                        $parts[] = $message;
                    }
                    break;

                case 'confirmation':
                    $content = $step['content'] ?? [];
                    $message = self::toString($content['message'] ?? '');
                    $tool    = self::toString($content['tool_name'] ?? 'unknown');
                    $parts[] = $message !== ''
                        ? '🔐 Xác nhận: ' . $message
                        : '🔐 Hành động cần xác nhận: ' . $tool;
                    break;

                default:
                    break;
            }
        }

        return trim(implode("\n\n", $parts));
    }

    /**
     * Find the first confirmation step.
     */
    public static function findConfirmation(array $steps): ?array {
        foreach ($steps as $step) {
            if (($step['type'] ?? '') === 'confirmation') {
                return $step;
            }
        }

        return null;
    }

    private static function toString($value): string {
        if (is_array($value)) {
            return (string) wp_json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        }

        return trim((string) $value);
    }

    private static function extractMessage($value): string {
        if (is_array($value) && isset($value['message'])) {
            return self::toString($value['message']);
        }

        return self::toString($value);
    }
}
