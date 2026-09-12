<?php

declare(strict_types=1);

namespace Form2Email\Tests;

use PHPUnit\Framework\TestCase;

/**
 * End-to-end API test: boots the real application (index.php) behind the PHP
 * built-in server and performs genuine HTTP POSTs in pure POST/API mode (no
 * '_next' field), then verifies the delivered message in a local Mailpit
 * instance.
 *
 * This is the persistent counterpart to the manual "send an email through the
 * API" check: it exercises the full request pipeline (CORS/origin resolution,
 * honeypot, whitelist, validation, rate limiting, mailer dispatch) instead of
 * calling the mailer directly, which tests/MailpitMailerTest.php already does.
 *
 * Test isolation
 * --------------
 * Each scenario runs against a throwaway document root in the system temp
 * directory containing copies of index.php, mailer.php, mailer_phpmailer.php,
 * mailer_native.php and src/functions.php, a symlink to the repository's
 * vendor/ directory and a generated config.php for the fake domain
 * 'test.local'. The shared repository config.php (and its real credentials) is
 * never used.
 *
 * Mailpit requirement
 * -------------------
 * Like MailpitMailerTest, this suite expects a local Mailpit instance (SMTP
 * 127.0.0.1:1025, API 127.0.0.1:8025) and is skipped when it is unreachable.
 * It never deletes messages from the shared inbox.
 */
final class ApiSendEmailTest extends TestCase
{
    private const DEFAULT_SMTP_HOST = '127.0.0.1';
    private const DEFAULT_SMTP_PORT = 1025;
    private const DEFAULT_API_BASE  = 'http://127.0.0.1:8025/api/v1';

    private const TEST_ORIGIN = 'http://test.local';

    private string $smtpHost;
    private int    $smtpPort;
    private string $apiBase;
    private string $runId;

    /**
     * Resolves the Mailpit location and skips the suite when it is down.
     */
    protected function setUp(): void
    {
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

    /**
     * A single valid API-mode POST must return 200 with {"ok":true} and the
     * message must actually arrive in Mailpit.
     */
    public function test_api_mode_post_sends_email_and_returns_json_ok(): void
    {
        $receiver = 'api-e2e-' . $this->runId . '@example.com';

        $this->withServer($this->domainConfig($receiver, 100), function (string $baseUrl): void {
            $response = $this->post($baseUrl, $this->validFields());

            $this->assertSame(200, $response['status'], $response['body']);
            $this->assertSame(['ok' => true], $response['json']);
        });

        $message = $this->waitForMessage($receiver);
        $this->assertSame($this->subject(), $message['Subject']);
        $this->assertStringContainsString('Hallo aus dem API-Test', $message['Text']);
    }

    /**
     * Regression test for the rate-limit bug: failed/invalid submissions must
     * NOT consume the quota, and the limit must trigger only for valid send
     * attempts.
     */
    public function test_rate_limit_counts_only_valid_send_attempts(): void
    {
        $receiver = 'api-e2e-rl-' . $this->runId . '@example.com';

        $this->withServer($this->domainConfig($receiver, 2), function (string $baseUrl): void {
            // Invalid requests (broken email) return 400 and must not count.
            for ($i = 0; $i < 5; $i++) {
                $invalid = $this->post($baseUrl, [
                    'email'    => 'not-an-email',
                    'message'  => 'invalid attempt',
                    'honeypot' => $this->honeypot(),
                ]);
                $this->assertSame(400, $invalid['status'], $invalid['body']);
            }

            // Two valid sends are within max=2.
            $this->assertSame(200, $this->post($baseUrl, $this->validFields())['status']);
            $this->assertSame(200, $this->post($baseUrl, $this->validFields())['status']);

            // The third valid send exceeds the limit and is rejected.
            $third = $this->post($baseUrl, $this->validFields());
            $this->assertSame(429, $third['status'], $third['body']);
            $this->assertSame(['ok' => false, 'error' => 'Too many requests.'], $third['json']);
        });

        // The two allowed sends must have reached Mailpit.
        $this->waitForMessage($receiver);
    }

    // ---------------------------------------------------------------------
    // Application fixture
    // ---------------------------------------------------------------------

    /**
     * Builds the per-domain configuration for the fake 'test.local' origin.
     *
     * 'client_ip_header' is intentionally empty: the PHP built-in server is
     * reached directly, so REMOTE_ADDR is the correct client identity. This
     * also exercises the explicit direct-mode branch of rateLimitClientIp().
     *
     * @return array<string, mixed>
     */
    private function domainConfig(string $receiver, int $max): array
    {
        return [
            'receiver_email' => $receiver,
            'email_subject'  => $this->subject(),
            'honeypot_value' => $this->honeypot(),
            'whitelist'      => ['email', 'name', 'message', 'honeypot'],
            'rate_limit'     => [
                'max'              => $max,
                'window'           => 300,
                'client_ip_header' => '',
            ],
            'mailer' => [
                'type'    => 'phpmailer',
                'options' => [
                    'auth_type'  => 'password',
                    'host'       => $this->smtpHost,
                    'port'       => $this->smtpPort,
                    'encryption' => 'none',
                    'username'   => '',
                    'password'   => '',
                    'from_email' => 'noreply@example.com',
                    'from_name'  => 'Form2Email API E2E',
                ],
            ],
        ];
    }

    private function subject(): string
    {
        return sprintf('Form2Email API E2E [%s] %d', $this->runId, time());
    }

    private function honeypot(): string
    {
        return 'api-e2e-honeypot-' . $this->runId;
    }

    /**
     * @return array<string, string>
     */
    private function validFields(): array
    {
        return [
            'email'    => 'sender@example.com',
            'name'     => 'API Test',
            'message'  => 'Hallo aus dem API-Test',
            'honeypot' => $this->honeypot(),
        ];
    }

    // ---------------------------------------------------------------------
    // HTTP server harness
    // ---------------------------------------------------------------------

    /**
     * Provisions a temporary document root, starts the PHP built-in server on a
     * free port, runs the scenario and always tears everything down again.
     *
     * @param array<string, mixed> $domainConfig
     * @param callable(string):void $scenario
     */
    private function withServer(array $domainConfig, callable $scenario): void
    {
        $rootDir = dirname(__DIR__);
        $docRoot = sys_get_temp_dir() . '/form2email-api-e2e-' . bin2hex(random_bytes(6));

        if (!mkdir($docRoot, 0700, true) && !is_dir($docRoot)) {
            $this->fail('Could not create temporary document root: ' . $docRoot);
        }

        try {
            foreach (['index.php', 'mailer.php', 'mailer_phpmailer.php', 'mailer_native.php'] as $file) {
                if (!copy($rootDir . '/' . $file, $docRoot . '/' . $file)) {
                    $this->fail('Could not copy ' . $file . ' into the temporary document root.');
                }
            }

            if (!mkdir($docRoot . '/src', 0700)) {
                $this->fail('Could not create the src/ directory in the temporary document root.');
            }
            foreach (glob($rootDir . '/src/*.php') ?: [] as $sourceFile) {
                if (!copy($sourceFile, $docRoot . '/src/' . basename($sourceFile))) {
                    $this->fail('Could not copy ' . basename($sourceFile) . ' into the temporary document root.');
                }
            }

            if (!symlink($rootDir . '/vendor', $docRoot . '/vendor')) {
                $this->fail('Could not link vendor/. Run `composer install` first.');
            }

            // Give each scenario its own counter directory so tests never
            // interfere with each other or with the production cache.
            $domainConfig['rate_limit']['storage_dir'] = $docRoot . '/ratelimit';
            $this->writeConfig($docRoot, $domainConfig);

            $port    = $this->freePort();
            $logFile = $docRoot . '/server.log';
            $command = [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $docRoot];

            $process = proc_open($command, [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', $logFile, 'a'],
                2 => ['file', $logFile, 'a'],
            ], $pipes);

            if (!is_resource($process)) {
                $this->fail('Could not start the PHP built-in server.');
            }

            try {
                $this->waitForServer($port, $logFile);
                $scenario('http://127.0.0.1:' . $port);
            } finally {
                proc_terminate($process);
                proc_close($process);
            }
        } finally {
            $this->removeDirectory($docRoot);
        }
    }

    /**
     * Writes the generated config.php for the fake domain.
     *
     * @param array<string, mixed> $domainConfig
     */
    private function writeConfig(string $docRoot, array $domainConfig): void
    {
        $export   = var_export(['domains' => ['test.local' => $domainConfig]], true);
        $contents = "<?php\n"
            . "if (!defined('ACCESS')) {\n    die('Direct access not permitted.');\n}\n"
            . "return " . $export . ";\n";

        if (file_put_contents($docRoot . '/config.php', $contents) === false) {
            $this->fail('Could not write the generated config.php.');
        }
    }

    private function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($socket === false) {
            $this->fail('Could not allocate a free TCP port: ' . $errstr);
        }

        $name = (string)stream_socket_get_name($socket, false);
        fclose($socket);

        return (int)substr($name, strrpos($name, ':') + 1);
    }

    private function waitForServer(int $port, string $logFile): void
    {
        $deadline = microtime(true) + 5.0;
        $context  = stream_context_create(['http' => ['timeout' => 1, 'ignore_errors' => true]]);

        do {
            // Any HTTP response (e.g. the 403 for a missing Origin) means the
            // server accepted the connection and executed index.php.
            if (@file_get_contents('http://127.0.0.1:' . $port . '/index.php', false, $context) !== false) {
                return;
            }
            usleep(50000);
        } while (microtime(true) < $deadline);

        $this->fail(
            'The PHP built-in server did not become ready. Server log:' . PHP_EOL
            . (string)@file_get_contents($logFile)
        );
    }

    /**
     * Performs a form-encoded POST to the application's front controller.
     *
     * @param array<string, string> $fields
     * @return array{status:int, body:string, json:mixed}
     */
    private function post(string $baseUrl, array $fields): array
    {
        $context = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => "Content-Type: application/x-www-form-urlencoded\r\nOrigin: " . self::TEST_ORIGIN,
            'content'       => http_build_query($fields),
            'ignore_errors' => true,
            'timeout'       => 15,
        ]]);

        $body   = @file_get_contents($baseUrl . '/index.php', false, $context);
        $status = 0;

        foreach (http_get_last_response_headers() ?? [] as $headerLine) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $headerLine, $matches) === 1) {
                $status = (int)$matches[1];
                break;
            }
        }

        return [
            'status' => $status,
            'body'   => (string)$body,
            'json'   => json_decode((string)$body, true),
        ];
    }

    // ---------------------------------------------------------------------
    // Mailpit helpers
    // ---------------------------------------------------------------------

    /**
     * Polls the Mailpit inbox (read-only) for a message addressed to the given
     * recipient. Never deletes anything.
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
        $context = stream_context_create(['http' => ['timeout' => 1, 'ignore_errors' => true]]);

        return @file_get_contents($this->apiBase . '/messages?limit=1', false, $context) !== false;
    }

    /**
     * @return array<string, mixed>
     */
    private function apiGet(string $path): array
    {
        $json = file_get_contents($this->apiBase . $path);

        return json_decode((string)$json, true, 512, JSON_THROW_ON_ERROR);
    }

    // ---------------------------------------------------------------------
    // Filesystem helpers
    // ---------------------------------------------------------------------

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            if ($item->isDir() && !$item->isLink()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($dir);
    }
}
