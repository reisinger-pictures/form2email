<?php
// Prevent direct access
if (!defined('ACCESS')) {
    die('Direct access not permitted.');
}

// Include Composer's autoloader (also pulls in the shared helpers in
// src/functions.php).
require_once __DIR__ . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\OAuth;
use League\OAuth2\Client\Provider\Google;

/**
 * Sends an email using PHPMailer with SMTP (Password or XOAUTH2).
 *
 * @param array       $config       The application configuration array.
 * @param string      $subject      The email subject.
 * @param string      $message      The email body.
 * @param string      $replyToEmail The email address for the Reply-To header.
 * @param string|null &$error       (Optional) By-reference variable that receives a
 *                                  human-readable error description on failure.
 * @return bool True on success, false on failure.
 */
function send_email_phpmailer(array $config, string $subject, string $message, string $replyToEmail, ?string &$error = null): bool
{
    $mail = new PHPMailer(true);
    $mailerConfig = $config['mailer_options'];

    try {
        // Server settings
        // $mail->SMTPDebug = SMTP::DEBUG_SERVER; // Enable verbose debug output for troubleshooting
        $mail->isSMTP();
        $mail->Host = $mailerConfig['host'];
        $mail->Port = $mailerConfig['port'];

        // Force UTF-8 for headers and body. PHPMailer 7 defaults to ISO-8859-1,
        // which would mangle non-ASCII subjects/bodies (e.g. German umlauts)
        // into mojibake when encoded as =?iso-8859-1?...?. This contact form is
        // German-facing, so UTF-8 is mandatory for correct, client-compatible
        // rendering.
        $mail->CharSet = 'UTF-8';

        // Transport encryption strategy. The effective encryption mode is chosen
        // per domain in config.php ('encryption' option):
        //   - 'ssl'  -> implicit TLS (SMTPS) on the configured port
        //   - 'tls'  -> explicit TLS via STARTTLS (default)
        //   - 'none' / 'plain' -> no transport encryption (e.g. a local
        //     Mailpit/dev SMTP that does not advertise STARTTLS). SMTPAutoTLS is
        //     disabled here so PHPMailer never attempts a STARTTLS upgrade that
        //     the server would reject with "Command not implemented".
        $encryption = strtolower($mailerConfig['encryption'] ?? 'tls');

        // Authentication is required for the encrypted transports (ssl/tls)
        // against real providers. For an unencrypted local transport
        // ('none'/'plain', e.g. Mailpit) the SMTP server usually performs no
        // authentication, so SMTPAuth defaults to off there but can be forced on
        // per domain via the 'smtp_auth' flag.
        $defaultAuth   = ($encryption === 'none' || $encryption === 'plain') ? false : true;
        $mail->SMTPAuth = (bool)($mailerConfig['smtp_auth'] ?? $defaultAuth);

        if ($encryption === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($encryption === 'none' || $encryption === 'plain') {
            $mail->SMTPSecure   = '';
            $mail->SMTPAutoTLS  = false;
        } else {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        }

        // Authentication logic.
        // The active strategy is selected via $mailerConfig['auth_type'] per
        // domain in config.php ('password' for SMTP/Basic Auth, 'oauth2' for
        // Google XOAUTH2). All secrets are resolved there from the per-domain
        // environment variables (AGENTS.md §2), so they are read directly here.
        if ($mailerConfig['auth_type'] === 'oauth2') {
            $mail->AuthType = 'XOAUTH2';
            $oauthConfig    = $mailerConfig['oauth'] ?? [];
            $clientId       = $oauthConfig['clientId'] ?? null;
            $clientSecret   = $oauthConfig['clientSecret'] ?? null;
            $refreshToken   = $oauthConfig['refreshToken'] ?? null;

            $provider = new Google([
                'clientId'     => $clientId,
                'clientSecret' => $clientSecret,
            ]);
            $mail->setOAuth(new OAuth([
                'provider'     => $provider,
                'clientId'     => $clientId,
                'clientSecret' => $clientSecret,
                'refreshToken' => $refreshToken,
                'userName'     => $mailerConfig['username'],
            ]));
        } else { // Default to 'password'
            $mail->Username = $mailerConfig['username'];
            // The password is resolved per domain from the environment in config.php.
            $mail->Password = $mailerConfig['password'] ?? null;
        }

        // Recipients
        $mail->setFrom($mailerConfig['from_email'], $mailerConfig['from_name']);
        $mail->addAddress($config['receiver_email']);
        $mail->addReplyTo($replyToEmail);

        // Content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        // Render newlines as <br> so line breaks are honoured by ALL mail
        // clients. Relying solely on `white-space: pre-wrap` fails in clients
        // that ignore that CSS property (notably Outlook), collapsing the
        // message into a single line. The message is already HTML-escaped by
        // the caller (index.php uses htmlspecialchars), so nl2br() is safe
        // against injection.
        $mail->Body    = nl2br($message);
        $mail->AltBody = $message; // For non-HTML mail clients

        $mail->send();
        return true;
    } catch (\Throwable $e) {
        // Log the error for debugging purposes. Catching \Throwable (instead of
        // only \Exception) also covers Guzzle/OAuth failures that occur while
        // PHPMailer refreshes the XOAUTH2 access token: those exceptions are NOT
        // PHPMailer\PHPMailer\Exception instances, so $mail->ErrorInfo would be
        // empty and the raw exception message must be logged instead.
        $mailErrorInfo = $mail->ErrorInfo;
        $errorMessage  = $mailErrorInfo !== '' ? $mailErrorInfo : $e->getMessage();
        $error         = $errorMessage;

        error_log(sprintf(
            "Message could not be sent. Mailer Error: %s (Exception: %s)%s",
            $errorMessage,
            get_class($e),
            PHP_EOL . $e->getTraceAsString()
        ));
        return false;
    }
}
