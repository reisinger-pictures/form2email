# Deployment Guide (Portainer)

This document describes how to deploy the `form2email` application with Portainer.

## Architecture

We use an **Infrastructure as Code** (IaC) approach. The `docker-compose.yml`
file is version-controlled and stored in this repository. Portainer should pull
the stack configuration directly from this repository so the repository stays
the single source of truth (AGENTS.md §4).

The stack consists of two services:

1. `composer_init`: A one-shot container that installs the Composer dependencies
   (`vendor/`) into the bind-mount directory before the app starts.
2. `app`: The live PHP web application serving the form. It starts only after
   `composer_init` completed successfully.

Mail transport is chosen per domain in `config.php` via
`mailer.options.auth_type`: `password` (SMTP basic auth, e.g. Zoho Mail) or
`oauth2` (Google XOAUTH2). For the production domain `reisinger.pictures` the
active transport is Zoho Mail over STARTTLS (`smtppro.zoho.eu:587`).

### Configuration model (single source of truth)

To prevent configuration drift, the two configuration layers have strictly
separated responsibilities (AGENTS.md §2):

* **Non-secret defaults live ONLY in `config.php`** (host, port, encryption,
  username, sender). `docker-compose.yml` deliberately forwards these variables
  empty (`${VARIABLE_NAME:-}`), so a missing Portainer value falls back to
  `config.php`. A value set in Portainer still overrides the default.
* **Secrets have NO default anywhere.** `<PREFIX>_SMTP_PASSWORD` and the OAuth
  credentials MUST be set in Portainer. If password auth is active but the
  password is empty, `mailer_phpmailer.php` fails fast with a precise,
  secret-free log line instead of the generic "Could not authenticate".
* **Never commit or sync `.env*` files.** They are listed in `.gitignore` and
  excluded from `sync.sh`, because a secret file inside the webroot can be
  served by the reverse proxy (`.htaccess` is not honoured under PHP-FPM/Caddy).

## Deployment Steps

1. **Log into Portainer** and navigate to your environment.
2. Go to **Stacks** and click **Add stack**.
3. Enter a name for your stack (e.g., `form2email-production`).
4. Select the **Repository** build method (Recommended) or copy-paste the
   contents of `docker-compose.yml` into the Web editor.
    * *If using Repository:* Enter your Git repository URL. Specify the branch
      and the path to the `docker-compose.yml` file.
5. Scroll down to **Environment variables**.
6. Click **Add environment variable** and add the values described below.
   **Never commit these values to Git!**
7. Toggle **Enable relative path volumes** (if applicable to your Portainer
   setup) to ensure the volume binds work correctly.
8. Click **Deploy the stack**.

### Environment Variables

The configuration in `config.php` is fully per-domain. Each domain block carries
its own `mailer.options.auth_type`. All variables are therefore prefixed with
the upper-cased domain name: for `reisinger.pictures` the prefix is
`REISINGER_PICTURES_`; add a separate prefix per domain (e.g. `A_COM_` for
`a.com`).

#### Required when `auth_type=password` (SMTP basic auth)

| Variable Name | Description | Example / Format |
| :--- | :--- | :--- |
| `<PREFIX>_SMTP_PASSWORD` | **Secret.** SMTP password / app-specific password. No default: must be set. | `your-app-password` |
| `<PREFIX>_SMTP_HOST` | Optional override. Empty falls back to the `config.php` default. | `smtppro.zoho.eu` |
| `<PREFIX>_SMTP_PORT` | Optional override. Empty falls back to `587`. | `587` |
| `<PREFIX>_SMTP_ENCRYPTION` | Optional override: `tls` or `ssl`. Empty falls back to `tls`. | `tls` |
| `<PREFIX>_SMTP_USERNAME` | Optional override. Empty falls back to the `config.php` default (full mailbox address for Zoho Mail). | `florian@reisinger.pictures` |

Example for `reisinger.pictures`: only `REISINGER_PICTURES_SMTP_PASSWORD` is
mandatory. Leave host and username empty to use the `config.php` defaults
(`smtppro.zoho.eu`, `florian@reisinger.pictures`). Zoho Mail authenticates with
the full mailbox address and an **app-specific password**
(Zoho account -> Security -> App Passwords); a regular account password only
works while 2FA is disabled.

#### Required when `auth_type=oauth2` (Google XOAUTH2)

| Variable Name | Description | Example / Format |
| :--- | :--- | :--- |
| `<PREFIX>_OAUTH_CLIENT_ID` | Google OAuth2 Client ID. | `...apps.googleusercontent.com` |
| `<PREFIX>_OAUTH_CLIENT_SECRET` | **Secret.** Google OAuth2 Client Secret. | `GOCSPX-...` |
| `<PREFIX>_OAUTH_REFRESH_TOKEN` | **Secret.** Google OAuth2 Refresh Token. | `1//03a6...` |

> **Note:** Monitoring the validity of an OAuth token MUST NOT run inside the
> live application (AGENTS.md §3). If such monitoring is required, run it in a
> separate, isolated sidecar container and never in the request path.

## Updating the Stack

When you change `docker-compose.yml` in this repository:

1. Go to the Stack in Portainer.
2. Click on the **Editor** tab.
3. Click **Pull and update** (or manually click "Update the stack" if you used
   the Web editor method).

Application files (`config.php`, `index.php`, `src/**`, mailers) are deployed by
`./sync.sh`. Per AGENTS.md §6, always run the test suite and commit/push before
syncing.

## Troubleshooting

The mailer writes a structured, secret-free failure line on every send failure:

```
form2email mail send failed: mailer=phpmailer auth=password host=smtppro.zoho.eu
port=587 encryption=tls username=florian@reisinger.pictures from=...
receiver=... error="SMTP Error: Could not authenticate."
exception=PHPMailer\PHPMailer\Exception hint="SMTP authentication failed: ..."
```

* View it with `docker logs form-reisinger-pictures` (or in the Portainer log
  view). The SMTP password/OAuth secrets are never logged.
* Verify the effective environment delivered to the container:
  `docker exec form-reisinger-pictures env | grep REISINGER_PICTURES_SMTP`.
* `Could not authenticate` almost always means the username does not match the
  host (Zoho: full mailbox address, not `emailapikey`) or the app password was
  revoked/rotated. Regenerate it under Zoho account -> Security -> App Passwords
  and update `REISINGER_PICTURES_SMTP_PASSWORD` in Portainer.
* An empty password is reported explicitly ("SMTP password is not configured")
  instead of a generic authentication error.
