<?php

declare(strict_types=1);

namespace OpenClaw\Tests\Zalo;

use OpenClaw\Zalo\StepFormatter;
use OpenClaw\Tests\AbstractTestCase;

/**
 * Unit tests for Zalo\StepFormatter.
 *
 * @covers \OpenClaw\Zalo\StepFormatter
 */
class StepFormatterTest extends AbstractTestCase {

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

        $this->assertSame('⚠ Lỗi: API timeout', $result);
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
    public function format_renders_confirmation_step_with_its_message(): void {
        $steps = [
            ['type' => 'confirmation', 'content' => ['message' => 'Create post "Hello"?', 'tool_name' => 'wp_content_manager']],
        ];

        $result = StepFormatter::format($steps);

        $this->assertSame('🔐 Xác nhận: Create post "Hello"?', $result);
    }

    /** @test */
    public function format_renders_confirmation_fallback_when_message_is_empty(): void {
        $steps = [
            ['type' => 'confirmation', 'content' => ['message' => '', 'tool_name' => 'order_tool']],
        ];

        $result = StepFormatter::format($steps);

        $this->assertStringContainsString('🔐 Hành động cần xác nhận: order_tool', $result);
    }

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
}
