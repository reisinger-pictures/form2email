<?php
// Prevent direct access
if (!defined('ACCESS')) {
    die('Direct access not permitted.');
}

// Per-domain configuration.
//
// Each key is a bare host (and optional port) WITHOUT a scheme, e.g.
// 'example.com' or 'localhost:4321'. The active domain is resolved from the
// HTTP_ORIGIN header at runtime (see index.php + getDomainKeyFromOrigin()).
// There is deliberately NO 'default' fallback profile: an origin that does not
// match a configured domain is rejected with HTTP 403.
//
// A domain value is either a fully self-contained configuration array (including
// the mailer settings) or a string alias naming another domain key to reuse,
// e.g. 'www.a.com' => 'a.com'. Aliases avoid duplicating blocks and are
// resolved by resolveDomainConfig() (cycle-protected).
//
// Every per-domain block is fully self-contained, including the mailer
// settings. This allows each domain to use its own transport, e.g. domain A
// via Google XOAUTH2 (auth_type=oauth2) and domain B via SMTP basic auth
// (auth_type=password). Secrets MUST be injected via per-domain environment
// variables (see AGENTS.md §2); never hardcode them in this file.
//
// The redirect target is NOT part of the configuration: the form frontend MUST
// send a hidden '_next' field (e.g. the current page URL plus '?sent=true').
// The value is strictly validated against the request origin before the user
// is redirected. If '_next' is missing or invalid the request is rejected.
return [
    'domains' => [
        // --- Example domain A: Google XOAUTH2 ---
        'a.com' => [
            'receiver_email' => getenv('A_COM_RECEIVER_EMAIL') ?: 'info@a.com',
            'email_subject' => getenv('A_COM_EMAIL_SUBJECT') ?: 'New message from contact form',
            'honeypot_value' => getenv('A_COM_HONEYPOT_VALUE') ?: '00000000-0000-0000-0000-000000000000',

            // Case-insensitive whitelist of allowed form fields.
            // 'email', 'message' and 'honeypot' are required. '_next' is mandatory
            // and strictly validated against the request origin (see index.php).
            'whitelist' => ['email', 'name', 'message', 'phone', 'honeypot', 'subject', 'subject_prefix', '_next'],

            // Per-domain Make.com failure fallback (AGENTS.md §3). These override
            // the global MAKE_WEBHOOK_URL / MAKE_API_KEY variables for this domain.
            'make' => [
                'webhook_url' => getenv('A_COM_MAKE_WEBHOOK_URL') ?: null,
                'api_key' => getenv('A_COM_MAKE_API_KEY') ?: null,
            ],

            'mailer' => [
                'type' => 'phpmailer',
                'options' => [
                    'auth_type' => 'oauth2',

                    'host' => getenv('A_COM_SMTP_HOST') ?: 'smtp.gmail.com',
                    'port' => (int)(getenv('A_COM_SMTP_PORT') ?: 587),
                    'encryption' => getenv('A_COM_SMTP_ENCRYPTION') ?: 'tls',
                    'username' => getenv('A_COM_SMTP_USERNAME') ?: 'info@a.com',
                    'from_email' => getenv('A_COM_FROM_EMAIL') ?: 'info@a.com',
                    'from_name' => getenv('A_COM_FROM_NAME') ?: 'Contact form - a.com',

                    // --- Used for 'oauth2' auth_type (with Google) ---
                    // Secrets MUST come from the environment; never hardcode them here.
                    'oauth' => [
                        'clientId' => getenv('A_COM_OAUTH_CLIENT_ID') ?: null,
                        'clientSecret' => getenv('A_COM_OAUTH_CLIENT_SECRET') ?: null,
                        'refreshToken' => getenv('A_COM_OAUTH_REFRESH_TOKEN') ?: null,
                    ],

                    // --- Used for 'password' auth_type (SMTP/Basic Auth) ---
                    'password' => getenv('A_COM_SMTP_PASSWORD') ?: null,
                ],
            ],
        ],

        // --- Example domain B: SMTP basic auth ---
        'b.com' => [
            'receiver_email' => getenv('B_COM_RECEIVER_EMAIL') ?: 'info@b.com',
            'email_subject' => getenv('B_COM_EMAIL_SUBJECT') ?: 'New message from contact form',
            'honeypot_value' => getenv('B_COM_HONEYPOT_VALUE') ?: '11111111-1111-1111-1111-111111111111',
            'whitelist' => ['email', 'name', 'message', 'phone', 'honeypot', 'subject', 'subject_prefix', '_next'],

            'mailer' => [
                'type' => 'phpmailer',
                'options' => [
                    'auth_type' => 'password',

                    'host' => getenv('B_COM_SMTP_HOST') ?: 'smtp.example.com',
                    'port' => (int)(getenv('B_COM_SMTP_PORT') ?: 587),
                    'encryption' => getenv('B_COM_SMTP_ENCRYPTION') ?: 'tls',
                    'username' => getenv('B_COM_SMTP_USERNAME') ?: 'info@b.com',
                    'from_email' => getenv('B_COM_FROM_EMAIL') ?: 'info@b.com',
                    'from_name' => getenv('B_COM_FROM_NAME') ?: 'Contact form - b.com',

                    'oauth' => [
                        'clientId' => getenv('B_COM_OAUTH_CLIENT_ID') ?: null,
                        'clientSecret' => getenv('B_COM_OAUTH_CLIENT_SECRET') ?: null,
                        'refreshToken' => getenv('B_COM_OAUTH_REFRESH_TOKEN') ?: null,
                    ],

                    // --- Used for 'password' auth_type (SMTP/Basic Auth) ---
                    // Secrets MUST come from the environment; never hardcode them here.
                    'password' => getenv('B_COM_SMTP_PASSWORD') ?: null,
                ],
            ],
        ],

        // --- Aliases: several hosts sharing one domain block (no duplication) ---
        // 'www.a.com' routes to the same block as 'a.com'.
        'www.a.com' => 'a.com',
        // Local development profiles (e.g. Astro dev servers) reuse 'a.com'.
        'localhost:4321' => 'a.com',
        'localhost:4322' => 'a.com',
    ],
];
