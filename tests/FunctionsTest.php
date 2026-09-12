<?php

declare(strict_types=1);

namespace Form2Email\Tests;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Unit tests for the shared pure helpers declared in src/functions.php.
 *
 * These functions are the security-critical surface of the application
 * (whitelist enforcement, open-redirect mitigation, secret resolution) and
 * MUST be covered by automated tests to guard against regressions.
 */
final class FunctionsTest extends TestCase
{
    // ---------------------------------------------------------------------
    // areFieldsWhitelisted()
    // ---------------------------------------------------------------------

    public function test_areFieldsWhitelisted_returns_true_when_all_fields_allowed(): void
    {
        $fields    = ['email' => 'a@b.com', 'name' => 'John', 'message' => 'hi'];
        $whitelist = ['email', 'name', 'message'];

        $this->assertTrue(areFieldsWhitelisted($fields, $whitelist));
    }

    public function test_areFieldsWhitelisted_is_case_insensitive(): void
    {
        // The application normalises via strtolower(); ensure exact-match is
        // not required (defends against UI / HTML casing drift).
        $fields    = ['EMAIL' => 'a@b.com', 'Name' => 'John'];
        $whitelist = ['email', 'name'];

        $this->assertTrue(areFieldsWhitelisted($fields, $whitelist));
    }

    public function test_areFieldsWhitelisted_rejects_unknown_field(): void
    {
        // This is the whitelist bypass guard: an attacker-supplied extra key
        // (e.g., a forged submit button or hidden injection) MUST be rejected.
        $fields    = ['email' => 'a@b.com', 'role' => 'admin'];
        $whitelist = ['email', 'name', 'message'];

        $this->assertFalse(areFieldsWhitelisted($fields, $whitelist));
    }

    public function test_areFieldsWhitelisted_accepts_empty_input(): void
    {
        $this->assertTrue(areFieldsWhitelisted([], ['email', 'name']));
    }

    public function test_areFieldsWhitelisted_handles_numeric_field_names(): void
    {
        // PHP converts the form field name "0" into an integer array key. Under
        // strict_types, strtolower() would throw a TypeError without the string
        // cast, turning the request into an uncaught fatal error (HTTP 500).
        $this->assertFalse(areFieldsWhitelisted(['0' => 'x'], ['email', 'name']));
    }

    // ---------------------------------------------------------------------
    // getOriginFromUrl()
    // ---------------------------------------------------------------------

    /**
     * @return array<string, array{0:string, 1:string}>
     */
    #[DataProvider('provide_valid_origins')]
    public function test_getOriginFromUrl_normalises_valid_urls(string $url, string $expected): void
    {
        $this->assertSame($expected, getOriginFromUrl($url));
    }

    /** @return array<string, array{0:string, 1:string}> */
    public static function provide_valid_origins(): array
    {
        return [
            'plain https'              => ['https://example.com/thank-you', 'https://example.com'],
            'http with path and query' => ['http://example.com/path?x=1', 'http://example.com'],
            'uppercase host'           => ['https://EXAMPLE.COM/Path', 'https://example.com'],
            'explicit port'            => ['https://example.com:8443/x', 'https://example.com:8443'],
        ];
    }

    #[DataProvider('provide_open_redirect_attempts')]
    public function test_getOriginFromUrl_blocks_open_redirect_payloads(string $malicious): void
    {
        // Regression guard for the open-redirect class. None of these payloads
        // may produce an attacker-controlled origin.
        $this->assertSame('', getOriginFromUrl($malicious));
    }

    /** @return array<string, array{0:string}> */
    public static function provide_open_redirect_attempts(): array
    {
        return [
            'protocol-relative'  => ['//attacker.com/path'],
            'backslash bypass'   => ['https:\\\\attacker.com'],
            'javascript scheme'  => ['javascript://attacker.com/%0aalert(1)'],
            'data scheme'        => ['data:text/html,<script>alert(1)</script>'],
            'file scheme'        => ['file:///etc/passwd'],
            'missing host'       => ['https:///path-only'],
            'garbage'            => ['not-a-url'],
            'empty string'       => [''],
        ];
    }

    // ---------------------------------------------------------------------
    // getDomainKeyFromOrigin()
    // ---------------------------------------------------------------------

    /**
     * @return array<string, array{0:string, 1:string}>
     */
    #[DataProvider('provide_valid_origins_for_domain_key')]
    public function test_getDomainKeyFromOrigin_extracts_host(string $origin, string $expected): void
    {
        $this->assertSame($expected, getDomainKeyFromOrigin($origin));
    }

    /** @return array<string, array{0:string, 1:string}> */
    public static function provide_valid_origins_for_domain_key(): array
    {
        return [
            'plain https'             => ['https://reisinger.pictures', 'reisinger.pictures'],
            'http with port'          => ['http://localhost:4321', 'localhost:4321'],
            'uppercase host'          => ['HTTPS://EXAMPLE.COM/Path', 'example.com'],
            'with path and query'     => ['https://example.com/form?x=1', 'example.com'],
            'default https port'      => ['https://example.com:443/x', 'example.com'],
            'default http port'       => ['http://example.com:80/x', 'example.com'],
            'non-default port kept'   => ['https://example.com:8443/x', 'example.com:8443'],
        ];
    }

    #[DataProvider('provide_invalid_origins_for_domain_key')]
    public function test_getDomainKeyFromOrigin_rejects_invalid_input(string $invalid): void
    {
        $this->assertSame('', getDomainKeyFromOrigin($invalid));
    }

    /** @return array<string, array{0:string}> */
    public static function provide_invalid_origins_for_domain_key(): array
    {
        return [
            'empty string'       => [''],
            'protocol-relative'  => ['//attacker.com/path'],
            'backslash bypass'   => ['https:\\\\attacker.com'],
            'javascript scheme'  => ['javascript://attacker.com/%0aalert(1)'],
            'data scheme'        => ['data:text/html,<script>alert(1)</script>'],
            'file scheme'        => ['file:///etc/passwd'],
            'missing host'       => ['https:///path-only'],
            'garbage'            => ['not-a-url'],
        ];
    }

    // ---------------------------------------------------------------------
    // resolveDomainConfig()
    // ---------------------------------------------------------------------

    public function test_resolveDomainConfig_returns_config_for_direct_key(): void
    {
        $domains = [
            'a.com' => ['receiver_email' => 'info@a.com'],
            'b.com' => ['receiver_email' => 'info@b.com'],
        ];

        $this->assertSame(['receiver_email' => 'info@b.com'], resolveDomainConfig($domains, 'b.com'));
    }

    public function test_resolveDomainConfig_follows_single_alias(): void
    {
        $domains = [
            'reisinger.pictures' => ['receiver_email' => 'florian@reisinger.pictures'],
            'localhost:4321' => 'reisinger.pictures',
        ];

        $this->assertSame(
            ['receiver_email' => 'florian@reisinger.pictures'],
            resolveDomainConfig($domains, 'localhost:4321')
        );
    }

    public function test_resolveDomainConfig_follows_alias_chain(): void
    {
        $domains = [
            'a.com' => ['receiver_email' => 'info@a.com'],
            'b.com' => 'a.com',
            'c.com' => 'b.com',
        ];

        $this->assertSame(['receiver_email' => 'info@a.com'], resolveDomainConfig($domains, 'c.com'));
    }

    public function test_resolveDomainConfig_returns_null_for_unknown_key(): void
    {
        $this->assertNull(resolveDomainConfig(['a.com' => ['receiver_email' => 'x']], 'b.com'));
    }

    public function test_resolveDomainConfig_returns_null_for_empty_key(): void
    {
        $this->assertNull(resolveDomainConfig(['a.com' => ['receiver_email' => 'x']], ''));
    }

    public function test_resolveDomainConfig_returns_null_for_dangling_alias(): void
    {
        $domains = [
            'a.com' => 'does-not-exist.com',
        ];

        $this->assertNull(resolveDomainConfig($domains, 'a.com'));
    }

    public function test_resolveDomainConfig_returns_null_for_alias_cycle(): void
    {
        $domains = [
            'a.com' => 'b.com',
            'b.com' => 'a.com',
        ];

        $this->assertNull(resolveDomainConfig($domains, 'a.com'));
        $this->assertNull(resolveDomainConfig($domains, 'b.com'));
    }

    public function test_resolveDomainConfig_returns_null_for_non_array_leaf(): void
    {
        $domains = [
            'a.com' => 42,
        ];

        $this->assertNull(resolveDomainConfig($domains, 'a.com'));
    }

    // ---------------------------------------------------------------------
    // sanitizeHeaderValue()
    // ---------------------------------------------------------------------

    #[DataProvider('provide_header_values')]
    public function test_sanitizeHeaderValue_strips_header_control_characters(string $input, string $expected): void
    {
        $this->assertSame($expected, sanitizeHeaderValue($input));
    }

    /** @return array<string, array{0:string, 1:string}> */
    public static function provide_header_values(): array
    {
        return [
            'plain value'        => ['Hello', 'Hello'],
            'crlf injection'     => ["Hello\r\nBcc: evil@example.com", 'HelloBcc: evil@example.com'],
            'lone cr'            => ["Hello\rBcc: evil@example.com", 'HelloBcc: evil@example.com'],
            'lone lf'            => ["Hello\nBcc: evil@example.com", 'HelloBcc: evil@example.com'],
            'nul byte'           => ["Hello\0World", 'HelloWorld'],
            'surrounding blanks' => ['  Hello  ', 'Hello'],
        ];
    }

    // ---------------------------------------------------------------------
    // pruneTimestamps() / isRateLimited()
    // ---------------------------------------------------------------------

    public function test_pruneTimestamps_keeps_only_timestamps_inside_the_window(): void
    {
        // now = 300, window = 100 -> keep (200, 300].
        $this->assertSame([290, 300], pruneTimestamps([100, 200, 290, 300, 999], 300, 100));
    }

    public function test_isRateLimited_is_false_below_the_limit(): void
    {
        $this->assertFalse(isRateLimited([100, 200], 3, 200, 300));
    }

    public function test_isRateLimited_is_true_when_the_limit_is_reached(): void
    {
        $this->assertTrue(isRateLimited([100, 150, 200], 3, 200, 300));
    }

    public function test_isRateLimited_ignores_timestamps_outside_the_window(): void
    {
        $this->assertFalse(isRateLimited([1, 2, 3], 3, 10_000, 300));
    }

    public function test_isRateLimited_is_disabled_for_non_positive_values(): void
    {
        $this->assertFalse(isRateLimited([100, 100, 100], 0, 100, 300));
        $this->assertFalse(isRateLimited([100, 100, 100], 3, 100, 0));
    }

    // ---------------------------------------------------------------------
    // rateLimitClientIp()
    // ---------------------------------------------------------------------

    public function test_rateLimitClientIp_prefers_the_forwarded_for_header(): void
    {
        $server = ['HTTP_X_FORWARDED_FOR' => '203.0.113.7', 'REMOTE_ADDR' => '10.0.0.1'];
        $this->assertSame('203.0.113.7', rateLimitClientIp($server));
    }

    public function test_rateLimitClientIp_does_not_trust_x_real_ip_by_default(): void
    {
        // Caddy passes a client-supplied X-Real-IP through unchanged, so it is
        // spoofable and must only be used when the proxy overwrites it. With the
        // default (X-Forwarded-For) the value is ignored and REMOTE_ADDR wins.
        $server = ['HTTP_X_REAL_IP' => '203.0.113.7', 'REMOTE_ADDR' => '10.0.0.1'];
        $this->assertSame('10.0.0.1', rateLimitClientIp($server));
    }

    public function test_rateLimitClientIp_uses_the_first_forwarded_for_entry(): void
    {
        $server = ['HTTP_X_FORWARDED_FOR' => '203.0.113.7, 10.0.0.1', 'REMOTE_ADDR' => '10.0.0.1'];
        $this->assertSame('203.0.113.7', rateLimitClientIp($server));
    }

    public function test_rateLimitClientIp_falls_back_for_an_invalid_header(): void
    {
        $server = ['HTTP_X_FORWARDED_FOR' => 'not-an-ip', 'REMOTE_ADDR' => '10.0.0.1'];
        $this->assertSame('10.0.0.1', rateLimitClientIp($server));
    }

    public function test_rateLimitClientIp_can_ignore_proxy_headers(): void
    {
        $server = ['HTTP_X_FORWARDED_FOR' => '203.0.113.7', 'REMOTE_ADDR' => '10.0.0.1'];
        $this->assertSame('10.0.0.1', rateLimitClientIp($server, ''));
    }

    public function test_rateLimitClientIp_never_returns_an_empty_string(): void
    {
        $this->assertSame('0.0.0.0', rateLimitClientIp([]));
    }
}
