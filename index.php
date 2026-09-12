<?php
// Define constant to allow config access
define('ACCESS', true);
ini_set('display_errors', '0');

// Include Composer autoloader (loads shared helpers in src/functions.php and
// the PHPMailer/OAuth dependencies used by the mailer dispatcher).
require_once __DIR__ . '/vendor/autoload.php';

// Include configuration and mailer
$config = include('config.php');
require_once('mailer.php'); // Include the mailer dispatcher

// --- CORS & DOMAIN RESOLUTION ---
// Resolve the request origin against the per-domain configuration. There is
// deliberately NO fallback profile: an unknown origin is rejected with 403.
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$domainKey = getDomainKeyFromOrigin($origin);
$domainConfig = $domainKey === '' ? null : resolveDomainConfig($config['domains'], $domainKey);

if ($domainConfig === null) {
    http_response_code(403);
    exit('Forbidden: Unknown origin.');
}

header('Access-Control-Allow-Origin: ' . $origin);
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests gracefully
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate honeypot field
    if (!isset($_POST['honeypot']) || $_POST['honeypot'] !== $domainConfig['honeypot_value']) {
        http_response_code(403);
        exit('Forbidden');
    }

    // --- RATE LIMITING (OPTIONAL, PER DOMAIN) ---
    // Origin validation and the honeypot are not secrets (the browser sends the
    // Origin header automatically and the honeypot value is part of the public
    // form markup), so a per-client limit is the actual abuse brake against
    // scripted submissions. Configured per domain via 'rate_limit'; when the
    // key is absent, no limiting happens.
    $rateLimit = $domainConfig['rate_limit'] ?? null;
    if (is_array($rateLimit) && (int)($rateLimit['max'] ?? 0) > 0) {
        require_once __DIR__ . '/src/ratelimit.php';

        $clientKey  = $domainKey . '|' . rateLimitClientIp(
            $_SERVER,
            (string)($rateLimit['client_ip_header'] ?? 'X-Forwarded-For')
        );
        $storageDir = (string)($rateLimit['storage_dir'] ?? (sys_get_temp_dir() . '/form2email-ratelimit'));
        $window     = (int)($rateLimit['window'] ?? 300);

        if (!allowRequest($storageDir, $clientKey, (int)$rateLimit['max'], $window)) {
            http_response_code(429);
            header('Retry-After: ' . max(1, $window));

            if (empty($_POST['_next'])) {
                header('Content-Type: application/json');
                echo json_encode(['ok' => false, 'error' => 'Too many requests.']);
                exit;
            }

            exit('Too many requests.');
        }
    }

    // Check if all fields are in the whitelist
    if (!areFieldsWhitelisted($_POST, $domainConfig['whitelist'])) {
        http_response_code(400);
        exit('Invalid form fields.');
    }

    // Reject non-string values (e.g. "name[]=x") up front. Array values would
    // otherwise reach htmlspecialchars() and abort with an uncaught TypeError
    // (HTTP 500) instead of a clean 400.
    foreach ($_POST as $value) {
        if (!is_string($value)) {
            http_response_code(400);
            exit('Invalid form fields.');
        }
    }

    // Validate mandatory email field
    if (empty($_POST['email']) || !filter_var($_POST['email'], FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        exit('Invalid email address.');
    }

    $userEmail = $_POST['email'];

    // Build email message using only allowed fields
    $message = '';
    foreach ($_POST as $key => $value) {
        // Skip special fields and any fields with an empty value
        if ($key === 'honeypot' || $key === 'subject' || $key === 'subject_prefix' || $key === "_next" || trim($value) === '') {
            continue;
        }
        $message .= ucfirst((string)$key) . ":\n" . htmlspecialchars($value) . "\n\n";
    }

    // Prepare email subject. The subject and its prefix are form-controlled and
    // are sanitised against mail header injection (CR/LF/NUL) as defence in
    // depth, even though both mailers already strip those characters.
    $emailSubject = $domainConfig['email_subject'];

    if (!empty($_POST['subject'])) {
        $emailSubject = htmlspecialchars($_POST['subject']);
    }

    if (!empty($_POST['subject_prefix'])) {
        $emailSubject = '[' . htmlspecialchars($_POST['subject_prefix']) . '] ' . $emailSubject;
    }

    $emailSubject = sanitizeHeaderValue($emailSubject);

    // --- REDIRECT TARGET (OPTIONAL, SAME-ORIGIN ONLY) ---
    // The request supports two modes:
    // 1. Legacy redirect mode: the frontend sends a hidden '_next' field
    //    pointing back to the form page (e.g. current URL plus '?sent=true').
    //    The value is strictly validated against the request origin; a missing
    //    or cross-origin target is rejected with 400.
    // 2. Pure POST/API mode: no '_next' field is sent. The request is treated
    //    as an API call and answered with a JSON response (200 on success,
    //    500 on failure) instead of a redirect.
    $isApiMode = false;
    $redirectUrl = null;

    if (empty($_POST['_next'])) {
        $isApiMode = true;
    } else {
        if (!filter_var($_POST['_next'], FILTER_VALIDATE_URL)) {
            http_response_code(400);
            exit('Invalid redirect target.');
        }

        $nextOrigin = getOriginFromUrl($_POST['_next']);
        $requestOrigin = getOriginFromUrl($origin);

        if (empty($nextOrigin) || $nextOrigin !== $requestOrigin) {
            http_response_code(400);
            exit('Invalid redirect target.');
        }

        $redirectUrl = $_POST['_next'];
    }

    // --- PER-DOMAIN MAILER CONFIGURATION ---
    // The active domain block is fully self-contained, so its mailer type and
    // options (SMTP host, credentials, sender identity) replace the effective
    // configuration used by the mailer dispatcher.
    $config['receiver_email'] = $domainConfig['receiver_email'];
    $config['mailer_type'] = $domainConfig['mailer']['type'];
    $config['mailer_options'] = $domainConfig['mailer']['options'];

    // Send email using the new mailer function
    $mailError = null;
    $success = send_email(
        $config,
        $emailSubject,
        $message,
        $userEmail,
        $mailError
    );

    // --- RESPONSE HANDLING ---
    if ($success) {
        if ($isApiMode) {
            // Pure POST/API mode: respond with JSON instead of redirecting.
            header('Content-Type: application/json');
            echo json_encode(['ok' => true]);
        } else {
            // Legacy redirect mode: bounce back to the validated '_next' target.
            header("Location: " . $redirectUrl);
        }
        exit;
    }

    // Failure: the Make.com webhook is no longer called from the live app (see
    // AGENTS.md §3). The error is returned to the client as a JSON 500 response
    // in API mode, or as a plain-text 500 response in legacy redirect mode.
    if ($isApiMode) {
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Failed to send email.']);
        exit;
    }

    http_response_code(500);
    exit('Failed to send email.');
}

// Fallback for non-POST requests
http_response_code(405);
exit('Method not allowed.');
