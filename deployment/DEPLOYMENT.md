# Deployment Guide (Portainer)

This document describes how to deploy the `form2email` application using Portainer.

## Architecture

We use an **Infrastructure as Code (IaC)** approach. The `docker-compose.yml` file is stored in this Git repository. Portainer should pull the stack configuration directly from this repository to ensure a Single Source of Truth.

The stack consists of two services:

1. `composer_init`: A one-shot container that installs the Composer dependencies (`vendor/`) into the bind-mount directory before the app starts. It also removes PHPMailer's `get_oauth_token.php` helper so that no OAuth bootstrap script is exposed by the web server. The `app` service starts only after it completed successfully.
2. `app`: The live PHP-FPM web application serving the form.

Mail transport is currently Zoho ZeptoMail via SMTP basic auth (`auth_type=password`). ZeptoMail uses a static API key with no token expiry, so there is no OAuth sidecar/monitoring container. The live app never calls Make.com: SMTP failures are returned to the client directly.

## Deployment Steps

1. **Log into Portainer** and navigate to your environment.
2. Go to **Stacks** and click **Add stack**.
3. Enter a name for your stack (e.g., `form2email-production`).
4. Select the **Repository** build method (Recommended) or copy-paste the contents of `docker-compose.yml` into the Web editor.
    * *If using Repository:* Enter your Git repository URL. Specify the branch and the path to the `docker-compose.yml` file.
5. Scroll down to **Environment variables**.
6. Click **Add environment variable** and add the following keys with your secure values. **Never commit these values to Git!**

### Required Environment Variables

The configuration in `config.php` is fully per-domain: each domain block carries
its own `mailer.options.auth_type` selecting the active strategy: `password`
(SMTP/Basic Auth) or `oauth2` (Google XOAUTH2). All variables are therefore
prefixed with the upper-cased, non-alphanumeric-stripped domain name.

For the `reisinger.pictures` domain the prefix is `REISINGER_PICTURES_`; add a
separate `PREFIX_` per configured domain (e.g. `A_COM_` for `a.com`,
`B_COM_` for `b.com`).

#### Required when `auth_type=password` (SMTP/Basic Auth, e.g. ZeptoMail)

| Variable Name | Description | Example / Format |
| :--- | :--- | :--- |
| `<PREFIX>_SMTP_PASSWORD` | SMTP password / API key. **This is the only secret that must always be set.** | `your-smtp-password` |
| `<PREFIX>_SMTP_HOST` | SMTP server hostname (optional, has sample default). | `smtp.zeptomail.eu` |
| `<PREFIX>_SMTP_PORT` | SMTP server port (optional, defaults to `587`). | `587` |
| `<PREFIX>_SMTP_ENCRYPTION` | Encryption mode: `tls`, `ssl` or `none` (optional, defaults to `tls`). | `tls` |
| `<PREFIX>_SMTP_USERNAME` | SMTP username (optional, falls back to config.php; ZeptoMail requires `emailapikey`). | `emailapikey` |

#### Required when `auth_type=oauth2` (Google XOAUTH2) for a domain

| Variable Name | Description | Example / Format |
| :--- | :--- | :--- |
| `<PREFIX>_OAUTH_CLIENT_ID` | Google OAuth2 Client ID. | `...apps.googleusercontent.com` |
| `<PREFIX>_OAUTH_CLIENT_SECRET` | Google OAuth2 Client Secret. | `GOCSPX-...` |
| `<PREFIX>_OAUTH_REFRESH_TOKEN` | Google OAuth2 Refresh Token. | `1//03a6...` |

Example for the `reisinger.pictures` domain (password auth):
`REISINGER_PICTURES_SMTP_PASSWORD`.

> **Note:** `MAKE_WEBHOOK_URL` / `MAKE_API_KEY` are no longer used by the live app.
> They were only needed by the removed token-checker sidecar and are **not**
> configured in `docker-compose.yml` anymore.

7. Toggle **Enable relative path volumes** (if applicable to your Portainer setup) to ensure the volume binds work correctly.
8. Click **Deploy the stack**.

## Reverse Proxy (Caddy)

The app container is only reachable through the shared external `webnet` via the
central Caddy configuration (separate `caddyfile` repository). The relevant site
block is:

```caddyfile
form.reisinger.pictures {
	import security_headers
	import compress
	reverse_proxy form-reisinger-pictures:9000 {
		transport fastcgi {
			env SCRIPT_FILENAME /var/www/html/index.php
		}
	}
}
```

Consequences for this application:

1. **Every path runs `index.php`.** Because `SCRIPT_FILENAME` is pinned to
   `/var/www/html/index.php`, Caddy routes `/vendor/...`, `/config.php`, etc. to
   the front controller. The Composer directory and the PHPMailer
   `get_oauth_token.php` helper are therefore **not** web-reachable (they are
   still removed by `composer_init` as defence in depth). `.htaccess` is
   irrelevant (Apache only).
2. **`X-Forwarded-For` is provided by Caddy.** Caddy overwrites
   `X-Forwarded-For` with the real client IP and ignores client-supplied values,
   so the per-domain rate limit uses `client_ip_header => 'X-Forwarded-For'`.
   Do **not** switch to `X-Real-IP`: Caddy passes a client-supplied `X-Real-IP`
   through unchanged, which would let an attacker rotate the value and bypass the
   limit.
3. **HSTS** is already provided by the shared `security_headers` snippet. The
   domain allow-list keys on `host[:port]` only, so both `http://` and
   `https://` origins are accepted; HSTS closes the downgrade gap.

## Rate Limiting

Each domain block may define a `rate_limit` array (see `config.sample.php`):

```php
'rate_limit' => [
    'max' => 5,           // allowed requests per window
    'window' => 300,      // window length in seconds
    'client_ip_header' => 'X-Forwarded-For',
    // 'storage_dir' => '/var/cache/form2email-ratelimit',
],
```

Requests over the limit receive HTTP `429 Too Many Requests` (JSON in API mode,
plain text in redirect mode). Omitting the key disables limiting. The limiter
fails open on storage errors, so a broken cache directory never blocks mail.

## Updating the Stack

When you make changes to the `docker-compose.yml` in this repository:
1. Go to the Stack in Portainer.
2. Click on the **Editor** tab.
3. Click **Pull and update** (or manually click "Update the stack" if you used the Web editor method).
