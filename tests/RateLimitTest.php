<?php

declare(strict_types=1);

namespace Form2Email\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the rate-limiting helpers.
 *
 * Covers the pure window logic (pruneTimestamps / isRateLimited), the
 * client-IP resolution including its fail-open contract, and the file-based
 * storage layer (allowRequest).
 */
final class RateLimitTest extends TestCase
{
    /**
     * Loads the storage layer, which guards against direct access via the
     * ACCESS constant and is not part of the Composer autoloader.
     */
    protected function setUp(): void
    {
        if (!defined('ACCESS')) {
            define('ACCESS', true);
        }

        require_once __DIR__ . '/../src/ratelimit.php';
    }

    // ---------------------------------------------------------------------
    // pruneTimestamps()
    // ---------------------------------------------------------------------

    public function test_pruneTimestamps_keeps_only_entries_inside_the_window(): void
    {
        $now        = 1_000_000;
        $timestamps = [
            $now - 299, // inside
            $now - 300, // exactly on the boundary -> dropped
            $now - 301, // stale -> dropped
            $now,       // now -> inside
            $now + 5,   // future -> dropped
            'not-an-int',
            12.5,
        ];

        $this->assertSame([$now - 299, $now], pruneTimestamps($timestamps, $now, 300));
    }

    public function test_pruneTimestamps_handles_empty_input(): void
    {
        $this->assertSame([], pruneTimestamps([], 1_000_000, 300));
    }

    // ---------------------------------------------------------------------
    // isRateLimited()
    // ---------------------------------------------------------------------

    public function test_isRateLimited_returns_false_below_the_limit(): void
    {
        $now = 1_000_000;
        $this->assertFalse(isRateLimited([$now - 1], 2, $now, 300));
    }

    public function test_isRateLimited_returns_true_at_the_limit(): void
    {
        $now = 1_000_000;
        $this->assertTrue(isRateLimited([$now - 2, $now - 1], 2, $now, 300));
    }

    public function test_isRateLimited_ignores_stale_entries(): void
    {
        $now        = 1_000_000;
        $timestamps = [$now - 400, $now - 500]; // both outside the 300s window

        $this->assertFalse(isRateLimited($timestamps, 2, $now, 300));
    }

    public function test_isRateLimited_is_disabled_for_non_positive_limits(): void
    {
        $now = 1_000_000;
        $this->assertFalse(isRateLimited([$now, $now, $now], 0, $now, 300));
        $this->assertFalse(isRateLimited([$now, $now, $now], 2, $now, 0));
    }

    // ---------------------------------------------------------------------
    // rateLimitClientIp()
    // ---------------------------------------------------------------------

    public function test_rateLimitClientIp_uses_first_entry_of_proxy_chain(): void
    {
        $server = ['HTTP_X_FORWARDED_FOR' => '203.0.113.7, 10.0.0.1, 10.0.0.2'];

        $this->assertSame('203.0.113.7', rateLimitClientIp($server, 'X-Forwarded-For'));
    }

    public function test_rateLimitClientIp_supports_custom_header_names(): void
    {
        $server = ['HTTP_X_REAL_IP' => '198.51.100.23'];

        $this->assertSame('198.51.100.23', rateLimitClientIp($server, 'X-Real-IP'));
    }

    public function test_rateLimitClientIp_returns_null_when_configured_header_is_missing(): void
    {
        // Regression guard for the global-lockout bug: behind a proxy,
        // REMOTE_ADDR is the proxy container's IP. Falling back to it would put
        // every visitor in one bucket, so the helper MUST return null instead.
        $server = ['REMOTE_ADDR' => '172.18.0.3'];

        $this->assertNull(rateLimitClientIp($server, 'X-Forwarded-For'));
    }

    public function test_rateLimitClientIp_returns_null_for_invalid_header_value(): void
    {
        $server = ['HTTP_X_FORWARDED_FOR' => 'not-an-ip', 'REMOTE_ADDR' => '172.18.0.3'];

        $this->assertNull(rateLimitClientIp($server, 'X-Forwarded-For'));
    }

    public function test_rateLimitClientIp_uses_remote_addr_in_explicit_direct_mode(): void
    {
        $server = ['REMOTE_ADDR' => '198.51.100.9'];

        $this->assertSame('198.51.100.9', rateLimitClientIp($server, ''));
    }

    public function test_rateLimitClientIp_returns_null_in_direct_mode_without_valid_remote_addr(): void
    {
        $this->assertNull(rateLimitClientIp([], ''));
        $this->assertNull(rateLimitClientIp(['REMOTE_ADDR' => 'garbage'], ''));
    }

    // ---------------------------------------------------------------------
    // allowRequest() (file-based storage)
    // ---------------------------------------------------------------------

    public function test_allowRequest_permits_up_to_max_then_blocks_per_key(): void
    {
        $dir = $this->temporaryDirectory();

        try {
            $this->assertTrue(allowRequest($dir, 'client-a', 2, 300));
            $this->assertTrue(allowRequest($dir, 'client-a', 2, 300));
            $this->assertFalse(allowRequest($dir, 'client-a', 2, 300));

            // A different client must have its own, independent bucket.
            $this->assertTrue(allowRequest($dir, 'client-b', 2, 300));
        } finally {
            $this->removeDirectory($dir);
        }
    }

    public function test_allowRequest_is_disabled_for_non_positive_limits(): void
    {
        $dir = $this->temporaryDirectory();

        try {
            $this->assertTrue(allowRequest($dir, 'client-a', 0, 300));
            $this->assertTrue(allowRequest($dir, 'client-a', 5, 0));

            // Disabled limiting must not even create the storage directory.
            $this->assertDirectoryDoesNotExist($dir);
        } finally {
            $this->removeDirectory($dir);
        }
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function temporaryDirectory(): string
    {
        $dir = sys_get_temp_dir() . '/form2email-ratelimit-test-' . bin2hex(random_bytes(6));

        return $dir;
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (glob($dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($dir);
    }
}
