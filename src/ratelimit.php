<?php
// Prevent direct access
if (!defined('ACCESS')) {
    die('Direct access not permitted.');
}

/**
 * File-based sliding-window rate limiter.
 *
 * Each client key is stored as one JSON file containing the Unix timestamps of
 * the requests inside the current window. The file is locked with flock() so
 * concurrent PHP-FPM workers cannot interleave their read-modify-write cycles.
 *
 * The limiter fails OPEN on any storage problem (missing/unwritable directory,
 * lock failure): a broken cache must never take the contact form down. It fails
 * CLOSED only when the client actually exceeded the configured limit.
 *
 * The window logic itself lives in the pure helper isRateLimited() /
 * pruneTimestamps() in src/functions.php, which is unit-tested separately.
 *
 * @param string $storageDir Writable directory for the counter files.
 * @param string $key        Stable client identity (e.g. "domain|ip").
 * @param int    $max        Maximum number of requests per window. Values <= 0 disable the limit.
 * @param int    $window     Sliding window length in seconds. Values <= 0 disable the limit.
 * @return bool True if the request is allowed, false if the limit is exceeded.
 */
function allowRequest(string $storageDir, string $key, int $max, int $window): bool
{
    if ($max <= 0 || $window <= 0) {
        return true;
    }

    if (!is_dir($storageDir) && !@mkdir($storageDir, 0700, true) && !is_dir($storageDir)) {
        return true; // Fail open.
    }

    $file = rtrim($storageDir, DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR
        . hash('sha256', $key)
        . '.json';

    $handle = @fopen($file, 'c+');
    if ($handle === false) {
        return true; // Fail open.
    }

    try {
        if (!flock($handle, LOCK_EX)) {
            return true; // Fail open.
        }

        $now        = time();
        $raw        = stream_get_contents($handle);
        $timestamps = json_decode((string)$raw, true);
        if (!is_array($timestamps)) {
            $timestamps = [];
        }

        // Drop everything outside the window before deciding.
        $timestamps = pruneTimestamps($timestamps, $now, $window);

        $allowed = count($timestamps) < $max;
        if ($allowed) {
            $timestamps[] = $now;
        }

        // Persist the pruned (and possibly extended) list.
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, json_encode($timestamps));
        fflush($handle);

        return $allowed;
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}
