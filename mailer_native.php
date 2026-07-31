<?php
// Prevent direct access
if (!defined('ACCESS')) {
    die('Direct access not permitted.');
}

/**
 * Sends an email using the native PHP mail() function.
 *
 * @param array       $config       The application configuration array.
 * @param string      $subject      The email subject.
 * @param string      $message      The email body.
 * @param string      $replyToEmail The email address for the Reply-To header.
 * @param string|null &$error       (Optional) By-reference variable that receives a
 *                                  human-readable error description on failure.
 * @return bool True on success, false on failure.
 */
function send_email_native(array $config, string $subject, string $message, string $replyToEmail, ?string &$error = null): bool
{
    // Store receiver email in a variable for clarity and to avoid repeated access.
    $receiverEmail = $config['receiver_email'];

    $headers = [
        // The From header is typically the same as the recipient for this use case
        'From' => $receiverEmail,
        'Reply-To' => $replyToEmail
    ];

    $success = mail(
        $receiverEmail,
        $subject,
        $message,
        $headers
    );

    if (!$success) {
        $lastError = error_get_last();
        $error     = $lastError ? $lastError['message'] : 'Unknown error while calling PHP mail().';
    }

    return $success;
}
