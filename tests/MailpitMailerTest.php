<?php

declare(strict_types=1);

namespace Form2Email\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Integration test: sends a REAL email through the PHPMailer transport to a
 * local Mailpit SMTP server and verifies the delivered message's compatibility
 * (correct addressing, Reply-To, multipart/alternative body, UTF-8 handling).
 *
 * Why Mailpit + how it is run
 * ----------------------------
 * Mailpit is expected to run as a SHARED local instance in the background
 * (e.g. via the Homebrew LaunchAgent `brew services start mailpit`). By default
 * it listens on SMTP 127.0.0.1:1025 and the HTTP API on 127.0.0.1:8025.
 *
 * This test NEVER deletes messages from the shared inbox: it only performs
 * HTTP GET requests against the Mailpit API. Every send carries a unique
 * subject marker (run id + timestamp) so the mails produced by this test can be
 * identified unambiguously even when other mails sit in the same inbox.
 *
 * If Mailpit is not reachable the whole suite is skipped, so `composer test`
 * keeps passing in environments without a local Mailpit (e.g. CI).
 *
 * Transport note: the local Mailpit SMTP advertises neither AUTH nor STARTTLS,
 * so the test uses the new 'none' encryption mode (see mailer_phpmailer.php),
 * which disables SMTPAutoTLS and authentication.
 */
final class MailpitMailerTest extends TestCase
{
    private const DEFAULT_SMTP_HOST = '127.0.0.1';
    private const DEFAULT_SMTP_PORT = 1025;
    private const DEFAULT_API_BASE  = 'http://127.0.0.1:8025/api/v1';

    private string $smtpHost;
    private int    $smtpPort;
    private string $apiBase;
    private string $runId;

    /**
     * Loads the mailer dispatcher and skips the suite when no Mailpit instance
     * is reachable on the configured host/port.
     */
    protected function setUp(): void
    {
        // The mailer dispatcher guards against direct access via the ACCESS
        // constant; defining it here is required before requiring mailer.php.
        if (!defined('ACCESS')) {
            define('ACCESS', true);
        }

        require_once __DIR__ . '/../vendor/autoload.php';
        require_once __DIR__ . '/../mailer.php';

        // Allow overriding the Mailpit location via the same env vars used by
        // the 'mailpit.local' profile in config.php.
        $this->smtpHost = getenv('MAILPIT_SMTP_HOST') ?: self::DEFAULT_SMTP_HOST;
        $this->smtpPort = (int)(getenv('MAILPIT_SMTP_PORT') ?: self::DEFAULT_SMTP_PORT);
        $this->apiBase  = getenv('MAILPIT_API_BASE') ?: self::DEFAULT_API_BASE;

        if (!$this->mailpitReachable()) {
            $this->markTestSkipped(sprintf(
                'Mailpit not reachable at %s:%d (API %s). Start it with `brew services start mailpit`.',
                $this->smtpHost,
                $this->smtpPort,
                $this->apiBase
            ));
        }

        $this->runId = bin2hex(random_bytes(8));
    }

    // ---------------------------------------------------------------------
    // Tests
    // ---------------------------------------------------------------------

    public function test_message_is_delivered_and_addressed_correctly(): void
    {
        $replyTo = 'user-' . $this->runId . '@example.com';
        $message = $this->sendAndFetch("Name:\nJohn Doe\n\nMessage:\nHello from the test", $replyTo, $subject);

        // The message must actually have been captured by Mailpit.
        $this->assertNotEmpty($message['ID']);
        $this->assertSame($subject, $message['Subject']);

        // Recipient: the configured receiver_email.
        $this->assertCount(1, $message['To']);
        $this->assertSame('recipient-' . $this->runId . '@example.com', $message['To'][0]['Address']);

        // Sender identity (From name + address).
        $this->assertSame('Form2Email Test Sender', $message['From']['Name']);
        $this->assertSame('noreply@example.com', $message['From']['Address']);

        // Reply-To must reflect the form submitter's address.
        $this->assertCount(1, $message['ReplyTo']);
        $this->assertSame($replyTo, $message['ReplyTo'][0]['Address']);
    }

    public function test_message_has_multipart_alternative_body(): void
    {
        $body    = "Name:\nJane Roe\n\nMessage:\nPlain and HTML should both be present";
        $message = $this->sendAndFetch($body, 'jane@example.com', $subject);

        // Both parts must exist -> proves a multipart/alternative MIME body was
        // built (compatible with HTML and text-only clients).
        $this->assertNotEmpty($message['Text'], 'Plain-text (AltBody) part is missing.');
        $this->assertNotEmpty($message['HTML'], 'HTML (Body) part is missing.');

        // The HTML body must convert newlines to <br> so line breaks render in
        // clients that ignore `white-space: pre-wrap` (e.g. Outlook). Relying on
        // pre-wrap alone would collapse the message into a single line there.
        $this->assertStringContainsString('<br', $message['HTML']);
        $this->assertStringContainsString('Jane Roe', $message['HTML']);
        $this->assertStringContainsString('Plain and HTML should both be present', $message['HTML']);

        // The user content must be present (and identical) in both representations.
        // Mailpit normalises line endings to CRLF, so compare on a normalised form.
        $normalizedText = str_replace("\r\n", "\n", $message['Text']);
        $this->assertStringContainsString($body, $normalizedText);
    }

    public function test_utf8_special_characters_are_preserved(): void
    {
        $body    = "Nachricht:\nGrüße aus München – äöüß € ½ ✓";
        $subject = 'Umlaut-Subject äöüß – ' . $this->runId;
        $config  = $this->buildConfig();
        $error   = null;

        $sent = send_email($config, $subject, $body, 'utf8@example.com', $error);
        $this->assertTrue($sent, 'Mailer reported failure: ' . $error);

        $message = $this->waitForMessage($this->expectedRecipient());

        // Subject and body must survive the SMTP transport as valid UTF-8
        // (i.e. PHPMailer must encode as =?utf-8?..., not =?iso-8859-1?...).
        $this->assertSame($subject, $message['Subject']);
        $this->assertStringContainsString('Grüße aus München', $message['Text']);
        $this->assertStringContainsString('äöüß € ½ ✓', $message['HTML']);
    }

    public function test_reply_to_header_matches_submitter(): void
    {
        $replyTo = 'submitter-' . $this->runId . '@example.org';
        $message = $this->sendAndFetch("Message:\nping", $replyTo, $subject);

        $this->assertSame($replyTo, $message['ReplyTo'][0]['Address'] ?? '');
        // The Return-Path (envelope sender) should equal the configured From.
        $this->assertSame('noreply@example.com', $message['ReturnPath'] ?? '');
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /**
     * Builds a self-contained per-domain mailer configuration that targets the
     * local Mailpit instance (unencrypted, no auth).
     *
     * @return array<string, mixed>
     */
    private function buildConfig(): array
    {
        return [
            'receiver_email' => 'recipient-' . $this->runId . '@example.com',
            'mailer_type'    => 'phpmailer',
            'mailer_options' => [
                'auth_type'  => 'password',
                'host'       => $this->smtpHost,
                'port'       => $this->smtpPort,
                'encryption' => 'none',
                'username'   => '',
                'password'   => '',
                'from_email' => 'noreply@example.com',
                'from_name'  => 'Form2Email Test Sender',
            ],
        ];
    }

    /**
     * Sends a message via the real mailer and returns the full message object
     * fetched from the Mailpit API, identified by its unique recipient address
     * (which is ASCII and therefore never RFC 2047 encoded, unlike the subject).
     *
     * @return array<string, mixed>
     */
    private function sendAndFetch(string $body, string $replyTo, ?string &$subject = null): array
    {
        $subject = sprintf('Form2Email compatibility test [%s] %d', $this->runId, time());
        $config  = $this->buildConfig();
        $error   = null;

        $sent = send_email($config, $subject, $body, $replyTo, $error);
        $this->assertTrue($sent, 'Mailer reported failure: ' . $error);

        return $this->waitForMessage($this->expectedRecipient());
    }

    private function expectedRecipient(): string
    {
        return 'recipient-' . $this->runId . '@example.com';
    }

    /**
     * Polls the Mailpit inbox (read-only) for a message addressed to the given
     * recipient. Only performs GET requests; never deletes anything.
     *
     * @return array<string, mixed>
     */
    private function waitForMessage(string $recipient): array
    {
        $deadline = microtime(true) + 3.0;

        do {
            $list = $this->apiGet('/messages?limit=100');
            foreach ($list['messages'] ?? [] as $meta) {
                $addresses = [];
                foreach ((array)($meta['To'] ?? []) as $entry) {
                    $addresses[] = is_array($entry) ? ($entry['Address'] ?? '') : (string)$entry;
                }
                if (in_array($recipient, $addresses, true)) {
                    return $this->apiGet('/message/' . $meta['ID']);
                }
            }
            usleep(150000);
        } while (microtime(true) < $deadline);

        $this->fail('Sent message not found in Mailpit inbox for recipient: ' . $recipient);
    }

    private function mailpitReachable(): bool
    {
        $ctx = stream_context_create(['http' => ['timeout' => 1, 'ignore_errors' => true]]);
        $result = @file_get_contents($this->apiBase . '/messages?limit=1', false, $ctx);
        return $result !== false;
    }

    /**
     * @return array<string, mixed>
     */
    private function apiGet(string $path): array
    {
        $json = file_get_contents($this->apiBase . $path);
        return json_decode((string)$json, true, 512, JSON_THROW_ON_ERROR);
    }
}
