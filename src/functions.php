<?php
/**
 * Shared pure helper functions for the Form2Email application.
 *
 * This file is autoloaded via composer.json ("autoload.files") so that both the
 * live web entry point (index.php) and the PHPMailer integration
 * (mailer_phpmailer.php) as well as the test suite can reuse the same building
 * blocks without triggering request execution.
 *
 * Each function in this file MUST remain free of side effects (no I/O, no
 * superglobal mutation) so it can be exercised by unit tests.
 */

declare(strict_types=1);

/**
 * Checks if all provided fields are in a whitelist (case-insensitive).
 *
 * @param array $fields    The array of fields to check (e.g., $_POST).
 * @param array $whitelist The array of allowed field names.
 * @return bool True if all fields are whitelisted, false otherwise.
 */
function areFieldsWhitelisted(array $fields, array $whitelist): bool
{
    // Cast defensively: PHP converts numeric form field names (e.g. "0") to
    // integer array keys, and strict_types would make strtolower() throw a
    // TypeError on those otherwise.
    $lowercaseWhitelist = array_map(
        static fn ($allowed): string => strtolower((string)$allowed),
        $whitelist
    );
    foreach (array_keys($fields) as $field) {
        if (!in_array(strtolower((string)$field), $lowercaseWhitelist, true)) {
            return false;
        }
    }
    return true;
}

/**
 * Safely extracts and normalizes the origin from a given URL to prevent open
 * redirect vulnerabilities. It strictly filters against protocol-relative paths
 * and malformed slash combinations.
 *
 * @param string $url The URL to parse and validate.
 * @return string The normalized origin (scheme://host[:port]) or an empty string if invalid.
 */
function getOriginFromUrl(string $url): string
{
    // Block protocol-relative URLs (e.g., //attacker.com) and backslashes to prevent parser bypasses
    if (str_starts_with($url, '//') || str_contains($url, '\\')) {
        return '';
    }

    $parsed = parse_url($url);
    if (!$parsed || empty($parsed['host']) || empty($parsed['scheme'])) {
        return '';
    }

    // Enforce web-safe protocols only
    $scheme = strtolower($parsed['scheme']);
    if (!in_array($scheme, ['http', 'https'], true)) {
        return '';
    }

    $port = isset($parsed['port']) ? ':' . $parsed['port'] : '';
    return $scheme . '://' . strtolower($parsed['host']) . $port;
}

/**
 * Extracts the bare domain key (host[:port]) from a full request origin.
 *
 * This is the reverse operation of getOriginFromUrl(): it maps a CORS
 * Origin header value (e.g. "https://reisinger.pictures" or
 * "http://localhost:4321") to the bare host key used in the per-domain
 * configuration array (e.g. "reisinger.pictures" or "localhost:4321").
 * Configuring domains without a scheme keeps the configuration free of
 * protocol noise and forces explicit same-origin redirect validation.
 *
 * @param string $origin The raw HTTP_ORIGIN header value.
 * @return string The normalized "host[:port]" domain key, or an empty string if invalid.
 */
function getDomainKeyFromOrigin(string $origin): string
{
    // Block protocol-relative URLs and backslashes to prevent parser bypasses
    if ($origin === '' || str_starts_with($origin, '//') || str_contains($origin, '\\')) {
        return '';
    }

    $parsed = parse_url($origin);
    if (!$parsed || empty($parsed['host']) || empty($parsed['scheme'])) {
        return '';
    }

    // Enforce web-safe protocols only
    $scheme = strtolower($parsed['scheme']);
    if (!in_array($scheme, ['http', 'https'], true)) {
        return '';
    }

    $host = strtolower($parsed['host']);
    $port = $parsed['port'] ?? null;

    // Strip default ports so "https://example.com:443" equals "https://example.com"
    if ($port === 80 || $port === 443) {
        $port = null;
    }

    return $port !== null ? $host . ':' . $port : $host;
}

/**
 * Resolves the effective configuration for a domain key, following alias
 * references so multiple hosts can share one domain block without duplicating
 * it in the configuration file.
 *
 * A domain entry is either an array (the effective configuration) or a string
 * naming another domain key to reuse (e.g. 'localhost:4322' => 'localhost:4321').
 * Alias chains are resolved with cycle protection; unresolvable references or
 * non-array leaves resolve to null.
 *
 * @param array  $domains   The full 'domains' configuration array.
 * @param string $domainKey The bare host[:port] key to resolve.
 * @return array|null The effective domain configuration, or null if unresolvable.
 */
function resolveDomainConfig(array $domains, string $domainKey): ?array
{
    if ($domainKey === '' || !isset($domains[$domainKey])) {
        return null;
    }

    $value = $domains[$domainKey];
    $visited = [];

    while (is_string($value)) {
        if ($value === '' || isset($visited[$value]) || !isset($domains[$value])) {
            return null;
        }
        $visited[$value] = true;
        $value = $domains[$value];
    }

    return is_array($value) ? $value : null;
}

/**
 * Removes characters that could break out of a mail header value.
 *
 * Mail headers are line-oriented: a raw carriage return (CR), line feed (LF)
 * or NUL byte inside a header value allows an attacker to append arbitrary
 * headers (mail header injection). Both PHPMailer (secureHeader()) and PHP's
 * mail() already strip these characters, but sanitising at the application
 * boundary is cheap defence in depth and keeps the intent explicit.
 *
 * @param string $value The raw header value (e.g. the form-supplied subject).
 * @return string The value with CR, LF and NUL removed and surrounding whitespace trimmed.
 */
function sanitizeHeaderValue(string $value): string
{
    return trim(str_replace(["\r", "\n", "\0"], '', $value));
}

/**
 * Filters a list of request timestamps down to those inside a sliding window.
 *
 * Pure helper used by the file-based rate limiter (src/ratelimit.php). Keeping
 * the window logic side-effect free makes it testable without touching disk.
 *
 * @param array $timestamps List of Unix timestamps of previous requests.
 * @param int   $now        The current Unix timestamp.
 * @param int   $window     The length of the sliding window in seconds.
 * @return array The timestamps that are inside ($now - $window, $now].
 */
function pruneTimestamps(array $timestamps, int $now, int $window): array
{
    $threshold = $now - $window;
    return array_values(array_filter(
        $timestamps,
        static fn ($timestamp): bool => is_int($timestamp) && $timestamp > $threshold && $timestamp <= $now
    ));
}

/**
 * Determines whether a new request would exceed the configured rate limit.
 *
 * @param array $timestamps List of Unix timestamps of previous requests.
 * @param int   $max        Maximum number of requests allowed per window. Values <= 0 disable the limit.
 * @param int   $now        The current Unix timestamp.
 * @param int   $window     The length of the sliding window in seconds. Values <= 0 disable the limit.
 * @return bool True if the limit is already reached (the request must be rejected), false otherwise.
 */
function isRateLimited(array $timestamps, int $max, int $now, int $window): bool
{
    if ($max <= 0 || $window <= 0) {
        return false;
    }
    return count(pruneTimestamps($timestamps, $now, $window)) >= $max;
}

/**
 * Resolves the client IP used as the rate-limit key.
 *
 * Prefers a reverse-proxy header (default "X-Forwarded-For") and falls back to
 * REMOTE_ADDR. X-Forwarded-For is the safe default in front of Caddy: Caddy
 * ignores any client-supplied X-Forwarded-For value and rewrites the header
 * with the real client IP, so it cannot be spoofed. Other headers such as
 * X-Real-IP are passed through unchanged by Caddy and MUST only be used when
 * the reverse proxy is explicitly configured to overwrite them. Note that
 * REMOTE_ADDR is the proxy container's IP when the app sits behind a proxy, so
 * the fallback collapses all clients into one bucket. X-Forwarded-For values
 * are reduced to their first entry.
 *
 * @param array  $server The $_SERVER superglobal.
 * @param string $header The HTTP header carrying the client IP; empty string uses REMOTE_ADDR only.
 * @return string A validated IP address (never empty).
 */
function rateLimitClientIp(array $server, string $header = 'X-Forwarded-For'): string
{
    if ($header !== '') {
        $normalized = 'HTTP_' . strtoupper(str_replace('-', '_', $header));
        if ($normalized !== 'HTTP_REMOTE_ADDR' && !empty($server[$normalized])) {
            $candidate = trim((string)$server[$normalized]);
            if (str_contains($candidate, ',')) {
                $candidate = trim(explode(',', $candidate)[0]);
            }
            if (filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
                return $candidate;
            }
        }
    }

    $remote = (string)($server['REMOTE_ADDR'] ?? '');
    return filter_var($remote, FILTER_VALIDATE_IP) !== false ? $remote : '0.0.0.0';
}
