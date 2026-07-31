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

    // Check if all fields are in the whitelist
    if (!areFieldsWhitelisted($_POST, $domainConfig['whitelist'])) {
        http_response_code(400);
        exit('Invalid form fields.');
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
        if ($key === 'honeypot' || $key === 'subject' || $key === 'subject_prefix' || $key === "_next" || trim((string)$value) === '') {
            continue;
        }
        $message .= ucfirst($key) . ":\n" . htmlspecialchars($value) . "\n\n";
    }

    // Prepare email subject
    $emailSubject = $domainConfig['email_subject'];

    if (!empty($_POST['subject'])) {
        $emailSubject = htmlspecialchars($_POST['subject']);
    }

    if (!empty($_POST['subject_prefix'])) {
        $emailSubject = '[' . htmlspecialchars($_POST['subject_prefix']) . '] ' . $emailSubject;
    }

    // --- REDIRECT TARGET (REQUIRED, SAME-ORIGIN ONLY) ---
    // The frontend MUST send a hidden '_next' field pointing back to the form
    // page (e.g. current URL plus '?sent=true'). There is no configured
    // fallback: a missing or cross-origin target is rejected with 400.
    if (empty($_POST['_next']) || !filter_var($_POST['_next'], FILTER_VALIDATE_URL)) {
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

    // --- MAKE.COM WEBHOOK FALLBACK LOGIC ---
    if ($success) {
        header("Location: " . $redirectUrl);
        exit;
    } else {
        // Read the Make.com Webhook URL and API Key from the environment. The
        // per-domain 'make' block (config.php) overrides the global
        // MAKE_WEBHOOK_URL / MAKE_API_KEY variables (AGENTS.md §3).
        $makeConfig = $domainConfig['make'] ?? [];
        $webhookUrl = !empty($makeConfig['webhook_url']) ? $makeConfig['webhook_url'] : getenv('MAKE_WEBHOOK_URL');
        $makeApiKey = !empty($makeConfig['api_key']) ? $makeConfig['api_key'] : getenv('MAKE_API_KEY');

        if ($webhookUrl && $makeApiKey) {
            // Include the error, the formatted message, and the raw POST data as a fallback
            $payload = json_encode([
                'error' => 'Live Code Error: The form on form.reisinger.pictures failed to send an email via SMTP! Mailer Error: ' . ($mailError ?? 'Unknown error'),
                'formatted_message' => $message,
                'form_data' => $_POST
            ]);

            $options = [
                'http' => [
                    'method' => 'POST',
                    'header' => "Content-Type: application/json\r\n" .
                        "x-make-apikey: " . $makeApiKey . "\r\n",
                    'content' => $payload,
                    'ignore_errors' => true // Prevent PHP warnings if Make.com is unreachable
                ]
            ];
            $context = stream_context_create($options);
            file_get_contents($webhookUrl, false, $context);
        }

        http_response_code(500);
        exit('Failed to send email.');
    }
}

// Fallback for non-POST requests
http_response_code(405);
exit('Method not allowed.');