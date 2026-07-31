<?php
// Prevent direct access
if (!defined('ACCESS')) {
    die('Direct access not permitted.');
}

/**
 * Sends an email based on the configuration.
 *
 * @param array       $config       The application configuration array.
 * @param string      $subject      The email subject.
 * @param string      $message      The email body.
 * @param string      $replyToEmail The email address for the Reply-To header.
 * @param string|null &$error       (Optional) By-reference variable that receives a
 *                                  human-readable error description on failure.
 * @return bool True on success, false on failure.
 */
function send_email(array $config, string $subject, string $message, string $replyToEmail, ?string &$error = null): bool
{
    // Default to the native mailer if not specified
    $mailerType = $config['mailer_type'] ?? 'native';

    if ($mailerType === 'phpmailer') {
        // Check if Composer dependencies are installed
        $autoloaderPath = __DIR__ . '/vendor/autoload.php';
        if (!file_exists($autoloaderPath)) {
            $error = 'PHPMailer is selected, but dependencies are not installed. Please run "composer install" in the script directory on your server.';
            http_response_code(500);
            // Provide a clear, direct error message.
            exit('Server Configuration Error: ' . $error);
        }
        require_once $autoloaderPath;
        require_once __DIR__ . '/mailer_phpmailer.php';
        return send_email_phpmailer($config, $subject, $message, $replyToEmail, $error);
    } else {
        require_once __DIR__ . '/mailer_native.php';
        return send_email_native($config, $subject, $message, $replyToEmail, $error);
    }
}
