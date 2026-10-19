# AI Agent Guidelines (AGENTS.md)

This document contains the repository-specific rules for AI agents (and human
developers) working on this project. Background, rationale, measurements and
history live in the project documentation (see `docs/decisions.md`); open work
in `AGENTS.todo.md`.

## 1. Agent Behavior & Documentation Strategy
* **Proactive Rule Suggestion:** If you identify a recurring issue, a missing security best practice, or a structural improvement, proactively suggest adding a new rule to this file.
* **Language Requirements:** All code, inline code comments and documentation (including this file) MUST be written in English. Text directed at the end user (e.g. chat responses) is in German.
* **Complete Code Output:** When proposing code changes or updating a file, ALWAYS output the complete code of that file — never snippets, diffs, or `// ... existing code ...` placeholders.
* **Code Documentation (PHPDoc):** Never remove or truncate existing PHPDoc comments. All methods and functions MUST be documented with standard PHPDoc blocks (description, `@param`, `@return`).

## 2. Environment & Security (Deployment)
* **Environment Variables (ENV):** Never hardcode sensitive data (API tokens, SMTP passwords, OAuth keys, webhook URLs) in the code or in `config.php`. Always read them via `getenv('VARIABLE_NAME')` in PHP, injected through the environment (e.g. Portainer stack variables).
* **Portainer Stack Updates:** When modifying `docker-compose.yml`, pass environment variables using the `${VARIABLE_NAME}` syntax so they can be managed centrally in the Portainer UI.
* **Secret Files Are Never Committed or Synced:** `.env` and `.env.*` MUST be listed in `.gitignore` and excluded from `sync.sh`. Secrets live only in the Portainer environment.
* **Single Source of Truth for Non-Secret Mail Settings:** The non-secret mail defaults (host, port, encryption, username, sender) MUST live ONLY in `config.php`. `docker-compose.yml` MUST forward these variables empty (`${VARIABLE_NAME:-}`). Duplicate defaults across both files are forbidden. Secrets (passwords/tokens) have NO default anywhere.

## 3. Architecture & Monitoring
* **Separation of Concerns (Health Checks):** Validating external connections (such as checking whether an OAuth token is still valid) MUST NEVER run inside the live web application code. If such monitoring is needed, it MUST run in a separate, isolated sidecar container in `docker-compose.yml` and MUST NOT block or mutate live request handling.
* **Request Modes (`_next` vs Pure POST/API):** Keep `index.php` to exactly the two documented request modes (see `README.md` → "Redirect Target"): a same-origin-validated redirect mode when the hidden `_next` field is present, and a pure POST/API JSON mode when `_next` is absent. There is no other fallback; a missing `_next` is deliberately NOT an error.
* **No External Error Webhooks:** The live application MUST NOT ship form data or error payloads to external webhooks (e.g. Make.com). SMTP failures are returned directly to the client — HTTP 500 with the JSON body `{"ok": false, "error": "Failed to send email."}` in pure POST/API mode (`_next` absent), HTTP 500 plain text in legacy redirect mode. Operational visibility comes from the structured, secret-free `error_log` line written by `mailer_phpmailer.php` (see section 5).

## 4. Infrastructure as Code (IaC)
* **Single Source of Truth:** `docker-compose.yml` MUST be version-controlled in this repository, and Portainer deployments SHOULD pull that file directly from the repository.

## 5. Test & Failover Discipline
* **Automated Tests for Security-Critical Code:** All security-critical or pure helper functions (whitelist enforcement, redirect-origin validation, secret/ENV resolution) MUST be covered by PHPUnit tests in `tests/`. New helpers in that surface area MUST ship with tests in the same change. The suite is executed via `composer test` (or `./vendor/bin/phpunit`) and MUST pass before a change is merged.
* **Failover Validation After Mailer Changes:** Any change to `mailer_phpmailer.php`, `mailer_native.php`, `mailer.php`, or the catch/exception flow in `index.php` MUST be followed by a manual forced-failure test (e.g. temporarily set an invalid `SMTP_PASSWORD` or stop the SMTP target). The test MUST verify that a pure POST/API request (no `_next`) returns the JSON `{"ok": false, "error": "Failed to send email."}` response with HTTP 500.
* **Secret-Free Mailer Logging:** Mailer failure logs MUST carry the transport context (host, port, encryption, auth type, username, sender, recipient) and an actionable hint, but MUST NEVER contain the SMTP password or OAuth secrets. `mailerContextSummary()` MUST serialise only an allow-list of non-secret fields and MUST stay covered by tests.
* **Mailpit Integration Testing & Email Client Compatibility:** `tests/MailpitMailerTest.php` MUST keep sending real emails to a local Mailpit instance (SMTP `127.0.0.1:1025`, UI/API `127.0.0.1:8025`, started via `brew services start mailpit`); it MUST NOT delete messages from the shared inbox and MUST skip when Mailpit is unreachable. Local/unencrypted SMTP transports MUST use `encryption => 'none'` (STARTTLS-free, no AUTH). `CharSet` MUST stay `'UTF-8'`. HTML bodies MUST convert newlines via `nl2br()` and MUST NOT rely on the CSS `white-space` property.

## 6. Sync Workflow (Deployment)
* **Mandatory Sync Order:** When the user requests a "sync" (deploying the current state to the live server via `./sync.sh`), the following order is REQUIRED:
  1. **Run the tests first.** Execute `composer test` (or `./vendor/bin/phpunit`). Do not proceed if the suite is failing.
  2. **Commit and push any open changes.** Do not sync with uncommitted/unpushed work left behind.
  3. **Only then run the sync** (`./sync.sh`).

## 7. Rate Limiting & Abuse Prevention
* **Limit the send, not the request:** The per-domain limiter MUST run *after* input validation (whitelist + email check) so only requests that actually attempt to send a mail consume the quota.
* **Never collapse clients into one bucket:** The rate-limit key is `domain|client-ip`, where the IP MUST come from a header the trusted reverse proxy overwrites (Caddy: `X-Forwarded-For`). If that header is missing or not a valid IP, the limiter MUST fail OPEN. `client_ip_header => ''` (explicit direct mode, trusting `REMOTE_ADDR`) is only allowed when the app is reachable without a proxy.
* **Storage failures fail open:** The file-based counter (`src/ratelimit.php`) MUST allow the request on any storage or lock error.
* **Tests:** The window logic (`pruneTimestamps`, `isRateLimited`), the client-IP resolution (including its fail-open contract) and the storage layer MUST stay covered by `tests/RateLimitTest.php`. The end-to-end API path is covered by `tests/ApiSendEmailTest.php`.

## 8. Scope of This File
* **Rules Only, No Decisions:** This file contains only rules — instructions that apply to all work in this repository. Permanent decisions, rationales, measurements and history do NOT belong here, but in `docs/`/`features/` or the project documentation; open items go to `AGENTS.todo.md`; completed work lives in the Git history.
