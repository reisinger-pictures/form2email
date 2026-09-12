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
    $lowercaseWhitelist = array_map('strtolower', $whitelist);
    foreach (array_keys($fields) as $field) {
        if (!in_array(strtolower($field), $lowercaseWhitelist, true)) {
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
 * Reads a configuration value with an environment variable fallback.
 *
 * This helper centralises the rule from AGENTS.md §2: secrets and other
 * deployment-specific values must never be hardcoded in config.php. If the
 * value is present in the config array it is preferred; otherwise the
 * matching environment variable is read via getenv().
 *
 * @param array  $configArray The configuration sub-array to look in (e.g., $mailerConfig or $oauth).
 * @param string $configKey   The key to look up inside $configArray.
 * @param string $envName     The environment variable name used as a fallback.
 * @return string|null The resolved value, or null if neither source provides one.
 */
function resolveMailerSecret(array $configArray, string $configKey, string $envName): ?string
{
    if (!empty($configArray[$configKey])) {
        return (string)$configArray[$configKey];
    }
    $envValue = getenv($envName);
    return $envValue === false ? null : (string)$envValue;
}

/**
 * Drops all timestamps that lie outside the current sliding window.
 *
 * The window is the half-open interval ($now - $window, $now]. Entries that are
 * stale (older than or exactly at the window boundary) or in the future are
 * removed. Non-integer values are discarded defensively so a corrupted counter
 * file cannot inflate the request count.
 *
 * @param array $timestamps List of Unix timestamps of previous requests.
 * @param int   $now        The current Unix timestamp.
 * @param int   $window     The length of the sliding window in seconds.
 * @return array The timestamps that are still inside the window.
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
 * This is a pure predicate: it never records the request itself. The storage
 * layer (src/ratelimit.php) is responsible for persisting timestamps, which
 * keeps this logic trivially unit-testable.
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
 * Resolves the client IP used as the rate-limit key, or null when it cannot be
 * determined reliably.
 *
 * Trust model (see AGENTS.md): behind a reverse proxy the header identified by
 * 'client_ip_header' is the only trustworthy source, because the proxy
 * overwrites it and ignores client-supplied values. When that header is
 * configured but missing or not a valid IP, this function returns null instead
 * of falling back to REMOTE_ADDR: behind a proxy REMOTE_ADDR is the proxy
 * container's IP, and using it would collapse every visitor into ONE shared
 * bucket (a global lock-out). Callers MUST fail open on a null result.
 *
 * Passing an empty $header explicitly selects direct mode (no proxy): the
 * caller then trusts REMOTE_ADDR. Use this only when the application is
 * reachable directly, never when it sits behind a proxy.
 *
 * @param array  $server The $_SERVER superglobal.
 * @param string $header The HTTP header carrying the client IP; '' uses REMOTE_ADDR (direct mode).
 * @return string|null A validated IP address, or null if none can be determined safely.
 */
function rateLimitClientIp(array $server, string $header = 'X-Forwarded-For'): ?string
{
    if ($header === '') {
        $remote = (string)($server['REMOTE_ADDR'] ?? '');
        return filter_var($remote, FILTER_VALIDATE_IP) !== false ? $remote : null;
    }

    $normalized = 'HTTP_' . strtoupper(str_replace('-', '_', $header));

    // 'REMOTE_ADDR' is deliberately not a valid HTTP header name here.
    if ($normalized === 'HTTP_REMOTE_ADDR'
        || !isset($server[$normalized])
        || !is_string($server[$normalized])
        || $server[$normalized] === ''
    ) {
        return null;
    }

    // Reduce a proxy chain list ("client, proxy1, proxy2") to the first entry,
    // which is the original client when the proxy overwrites the header.
    $candidate = trim($server[$normalized]);
    if (str_contains($candidate, ',')) {
        $candidate = trim(explode(',', $candidate)[0]);
    }

    return filter_var($candidate, FILTER_VALIDATE_IP) !== false ? $candidate : null;
}

/**
 * Builds a redaction-safe, single-line summary of the effective mail context.
 *
 * This helper exists so mailer failures can be logged with enough operational
 * detail (which transport, endpoint and identity were used) to diagnose the
 * problem immediately, WITHOUT ever writing a secret such as the SMTP password
 * or an OAuth token to the log. Only an explicit allow-list of non-secret
 * fields is serialised, so a future secret option cannot leak by accident.
 *
 * @param array $config       The effective application configuration.
 * @param array $mailerConfig The effective 'mailer_options' array.
 * @return string A "key=value" summary; empty values render as "-".
 */
function mailerContextSummary(array $config, array $mailerConfig): string
{
    $fields = [
        'mailer'     => (string)($config['mailer_type'] ?? 'unknown'),
        'auth'       => (string)($mailerConfig['auth_type'] ?? 'password'),
        'host'       => (string)($mailerConfig['host'] ?? ''),
        'port'       => (string)($mailerConfig['port'] ?? ''),
        'encryption' => (string)($mailerConfig['encryption'] ?? 'tls'),
        'username'   => (string)($mailerConfig['username'] ?? ''),
        'from'       => (string)($mailerConfig['from_email'] ?? ''),
        'receiver'   => (string)($config['receiver_email'] ?? ''),
    ];

    $parts = [];
    foreach ($fields as $key => $value) {
        $parts[] = $key . '=' . ($value === '' ? '-' : $value);
    }

    return implode(' ', $parts);
}

/**
 * Maps a raw mailer error message to an actionable operator hint.
 *
 * The goal is to turn a terse SMTP error (e.g. "Could not authenticate") into a
 * log line that tells the operator what to check first. Returns null when the
 * message matches no known pattern, so callers can omit the hint entirely.
 *
 * @param string $errorMessage The raw PHPMailer/transport error text.
 * @return string|null A short, actionable hint, or null if none applies.
 */
function mailerErrorHint(string $errorMessage): ?string
{
    $needle = strtolower($errorMessage);

    if (str_contains($needle, 'authenticate')
        || str_contains($needle, 'authentication')
        || str_contains($needle, '535')
    ) {
        return 'SMTP authentication failed: verify the SMTP username and the app-specific '
            . 'password (for Zoho Mail: account -> Security -> App Passwords). A revoked or '
            . 'rotated password must be updated in the environment.';
    }

    if (str_contains($needle, 'connection refused')
        || str_contains($needle, 'connect failed')
        || str_contains($needle, 'timed out')
        || str_contains($needle, 'could not connect')
    ) {
        return 'SMTP connection failed: verify host, port and encryption mode (tls/587 vs ssl/465).';
    }

    if (str_contains($needle, 'sender')
        || str_contains($needle, 'from address')
    ) {
        return 'Sender rejected: the From address must be a verified/authorized sender for the SMTP account.';
    }

    return null;
}
