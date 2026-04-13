<?php

declare(strict_types=1);

namespace OpenClaw\Tests\Zalo;

use OpenClaw\Zalo\ZaloController;
use OpenClaw\Tests\AbstractTestCase;

/**
 * Unit tests for Zalo\ZaloController helper logic.
 *
 * @covers \OpenClaw\Zalo\ZaloController
 */
class ZaloControllerTest extends AbstractTestCase {

    protected function setUp(): void {
        parent::setUp();
        $GLOBALS['wpoc_mock_users'] = [];
    }

    /** @test */
    public function is_allowed_user_returns_true_if_no_whitelist_configured(): void {
        $settings = ['zalo_allowed_user_ids' => ''];
        $result   = $this->invokePrivate('isAllowedUser', ['123', $settings]);

        $this->assertTrue($result);
    }

    /** @test */
    public function is_allowed_user_checks_whitelist_with_exact_matches(): void {
        $settings = ['zalo_allowed_user_ids' => ' 123, 456 '];

        $this->assertTrue($this->invokePrivate('isAllowedUser', ['123', $settings]));
        $this->assertTrue($this->invokePrivate('isAllowedUser', ['456', $settings]));
        $this->assertFalse($this->invokePrivate('isAllowedUser', ['12', $settings]));
    }

    /** @test */
    public function resolve_integration_user_id_returns_first_mock_admin_or_zero(): void {
        $GLOBALS['wpoc_mock_users'] = [99];
        $found = $this->invokePrivate('resolveIntegrationUserId');

        $GLOBALS['wpoc_mock_users'] = [];
        $missing = $this->invokePrivate('resolveIntegrationUserId');

        $this->assertSame(99, $found);
        $this->assertSame(0, $missing);
    }

    /** @test */
    public function session_key_formats_thread_and_user_params(): void {
        $key = $this->invokePrivate('sessionKey', ['thread-123', 'user-456']);

        $this->assertSame('wpoc_zalo_session_thread-123_user-456', $key);
    }

    /**
     * Invoke a private controller method.
     *
     * @return mixed
     */
    private function invokePrivate(string $method, array $args = []) {
        $controller = new ZaloController();
        $reflection = new \ReflectionMethod($controller, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($controller, $args);
    }
}
