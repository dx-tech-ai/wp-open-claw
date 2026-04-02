<?php

declare(strict_types=1);

namespace OpenClaw\Tests\Discord;

use OpenClaw\Discord\StepFormatter;
use OpenClaw\Tests\AbstractTestCase;

/**
 * Unit tests for Discord\StepFormatter.
 *
 * StepFormatter is a pure-PHP class that converts Kernel output steps into a
 * flat string suitable for posting into a Discord channel message.
 * It has no dependencies on WordPress or external services, so tests run fast.
 *
 * @covers \OpenClaw\Discord\StepFormatter
 */
class StepFormatterTest extends AbstractTestCase {

    // ──────────────────────────────────────────────
    // format() — happy paths
    // ──────────────────────────────────────────────

    /** @test */
    public function format_returns_empty_string_for_empty_steps(): void {
        $this->assertSame('', StepFormatter::format([]));
    }

    /** @test */
    public function format_renders_response_step_as_plain_text(): void {
        $steps = [
            ['type' => 'response', 'content' => 'Hello from AI!'],
        ];

        $result = StepFormatter::format($steps);

        $this->assertSame('Hello from AI!', $result);
    }

    /** @test */
    public function format_skips_empty_response_content(): void {
        $steps = [
            ['type' => 'response', 'content' => ''],
            ['type' => 'response', 'content' => 'Real answer.'],
        ];

        $result = StepFormatter::format($steps);

        $this->assertSame('Real answer.', $result);
    }

    /** @test */
    public function format_renders_error_step_with_error_prefix(): void {
        $steps = [
            ['type' => 'error', 'content' => 'API timeout'],
        ];

        $result = StepFormatter::format($steps);

        $this->assertSame('Error: API timeout', $result);
    }

    /** @test */
    public function format_renders_error_step_with_default_message_when_content_missing(): void {
        $steps = [
            ['type' => 'error'],
        ];

        $result = StepFormatter::format($steps);

        $this->assertSame('Error: Unknown error', $result);
    }

    /** @test */
    public function format_renders_observation_step_extracting_message_field(): void {
        $steps = [
            ['type' => 'observation', 'content' => ['message' => 'Tool executed successfully.', 'data' => []]],
        ];

        $result = StepFormatter::format($steps);

        $this->assertSame('Tool executed successfully.', $result);
    }

    /** @test */
    public function format_renders_observation_step_with_string_content(): void {
        $steps = [
            ['type' => 'observation', 'content' => 'Done.'],
        ];

        $result = StepFormatter::format($steps);

        $this->assertSame('Done.', $result);
    }

    /** @test */
    public function format_skips_empty_observation_content(): void {
        $steps = [
            ['type' => 'observation', 'content' => ['message' => '']],
            ['type' => 'response', 'content' => 'Final answer.'],
        ];

        $result = StepFormatter::format($steps);

        $this->assertSame('Final answer.', $result);
    }

    /** @test */
    public function format_renders_confirmation_step_with_its_message(): void {
        $steps = [
            ['type' => 'confirmation', 'content' => ['message' => 'Create post "Hello"?', 'tool_name' => 'wp_content_manager']],
        ];

        $result = StepFormatter::format($steps);

        $this->assertSame('Create post "Hello"?', $result);
    }

    /** @test */
    public function format_renders_confirmation_fallback_when_message_is_empty(): void {
        $steps = [
            ['type' => 'confirmation', 'content' => ['message' => '', 'tool_name' => 'order_tool']],
        ];

        $result = StepFormatter::format($steps);

        $this->assertStringContainsString('order_tool', $result);
    }

    /** @test */
    public function format_ignores_unknown_step_types(): void {
        $steps = [
            ['type' => 'thinking',  'content' => 'Internal reasoning...'],
            ['type' => 'response',  'content' => 'Visible reply.'],
            ['type' => 'debug_log', 'content' => 'Should be ignored.'],
        ];

        $result = StepFormatter::format($steps);

        $this->assertSame('Visible reply.', $result);
    }

    /** @test */
    public function format_joins_multiple_parts_with_double_newline(): void {
        $steps = [
            ['type' => 'response', 'content' => 'Part 1.'],
            ['type' => 'response', 'content' => 'Part 2.'],
        ];

        $result = StepFormatter::format($steps);

        $this->assertSame("Part 1.\n\nPart 2.", $result);
    }

    /** @test */
    public function format_trims_surrounding_whitespace(): void {
        $steps = [
            ['type' => 'response', 'content' => '  spaced   '],
        ];

        $this->assertSame('spaced', StepFormatter::format($steps));
    }

    // ──────────────────────────────────────────────
    // findConfirmation()
    // ──────────────────────────────────────────────

    /** @test */
    public function find_confirmation_returns_null_when_no_confirmation_step(): void {
        $steps = [
            ['type' => 'response', 'content' => 'All done.'],
        ];

        $this->assertNull(StepFormatter::findConfirmation($steps));
    }

    /** @test */
    public function find_confirmation_returns_first_confirmation_step(): void {
        $confirmStep = ['type' => 'confirmation', 'content' => ['message' => 'Proceed?', 'tool_name' => 'delete_tool']];
        $steps = [
            ['type' => 'response', 'content' => 'Working...'],
            $confirmStep,
            ['type' => 'response', 'content' => 'Done.'],
        ];

        $result = StepFormatter::findConfirmation($steps);

        $this->assertSame($confirmStep, $result);
    }

    /** @test */
    public function find_confirmation_returns_null_for_empty_steps(): void {
        $this->assertNull(StepFormatter::findConfirmation([]));
    }
}
