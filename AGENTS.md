# AI Agent Guidelines (AGENTS.md)

This document contains repository-specific context, architectural decisions, and rules for AI agents (and human developers) working on this project.

## 1. Agent Behavior & Documentation Strategy
* **Proactive Rule Suggestion:** As an AI agent, if you identify a recurring issue, a missing security best practice, or a structural improvement, proactively suggest adding a new rule to this `AGENTS.md` file.
* **Language Requirements:** All code, inline code comments, and documentation (like this file) MUST be written in English. Any text or instructions directed at the end user (e.g., chat responses) should be in German.
* **Complete Code Output:** When proposing code changes or updating a file, ALWAYS output the entire, complete code of that file. Do not use snippets, diffs, or omit sections with comments like `// ... existing code ...`. This strict requirement prevents copy-paste errors and ensures the user always has a fully functional file to deploy.
* **Code Documentation (PHPDoc):** Never remove or truncate existing PHPDoc comments. All methods and functions MUST be documented correctly using standard PHPDoc blocks, including detailed descriptions, `@param` types/descriptions, and `@return` types/descriptions.

## 2. Environment & Security (Deployment)
* **Environment Variables (ENV):** Never hardcode sensitive data (like API tokens, SMTP passwords, OAuth keys, or Webhook URLs) in the code or in `config.php`. Always use `getenv('VARIABLE_NAME')` in PHP. These values must be injected via the environment (e.g., via Portainer stack variables).
* **Portainer Stack Updates:** When modifying the `docker-compose.yml`, ensure that environment variables are passed using the `${VARIABLE_NAME}` syntax so they can be centrally managed in the Portainer UI.

## 3. Architecture & Monitoring
* **Separation of Concerns (Health Checks):** Validating external connections (such as checking if a Google OAuth token is valid) must NEVER be done within the live web application code. This polling logic must be placed in a separate, isolated sidecar container within the `docker-compose.yml`.
* **Request Modes (`_next` vs Pure POST/API):** `index.php` supports two request modes. If the form sends a hidden `_next` field, the request runs in legacy redirect mode: the value is same-origin validated and the user is redirected on success (400 on invalid/missing validation). If `_next` is ABSENT, the request is a pure POST/API call: no redirect, JSON response with HTTP 200 on success and HTTP 500 on failure. There is no other fallback; missing `_next` is deliberately NOT an error.
* **Proactive Error Alerting & Data Fallback:** The Make.com webhook (defined via `MAKE_WEBHOOK_URL` and `MAKE_API_KEY`) is used ONLY by the `token-checker` sidecar to alert the administrator when the Google OAuth2 refresh token (Gmail/XOAUTH2 = API path) has expired or is invalid. The live app (`index.php`) never calls Make.com: SMTP send failures are returned directly to the client as an HTTP 500 response (JSON body in pure POST/API mode, plain text in legacy redirect mode), and no form data is shipped to any external webhook.

## 4. Infrastructure as Code (IaC)
* **Single Source of Truth:** The `docker-compose.yml` file MUST be version-controlled and stored in this repository. Portainer deployments should pull this file directly from the repository. This prevents configuration drift and ensures that the repository remains the single source of truth for both application logic and infrastructure deployment.

## 5. Test & Failover Discipline
* **Automated Tests for Security-Critical Code:** All security-critical or pure helper functions (whitelist enforcement, redirect-origin validation, secret/ENV resolution, etc. — currently located in `src/functions.php`) MUST be covered by PHPUnit tests in `tests/`. New helpers in that surface area MUST ship with tests in the same change. The suite is executed via `composer test` (or `./vendor/bin/phpunit`) and MUST pass before a change is merged.
* **Failover Validation After Mailer Changes:** Any change to `mailer_phpmailer.php`, `mailer_native.php`, `mailer.php`, or the catch/exception flow in `index.php` MUST be followed by a manual forced-failure test (e.g., temporarily set an invalid `SMTP_PASSWORD` or stop the SMTP target). The test must verify that a pure POST/API request (no `_next`) returns the JSON `{"ok": false, "error": "Failed to send email."}` response with HTTP 500 AND that no Make.com webhook fires. This guards against silent regressions of the failure handler (such as catching the wrong `Exception` class).
* **Mailpit Integration Testing & Email Client Compatibility:** `tests/MailpitMailerTest.php` sends real emails to a local Mailpit instance for compatibility checks (addressing, Reply-To, multipart/alternative, UTF-8 round-trip, line breaks). Mailpit runs as a shared background service via `brew services start mailpit` (SMTP `127.0.0.1:1025`, UI/API `127.0.0.1:8025`); the test never deletes messages from the shared inbox and skips when Mailpit is unreachable. Local/unencrypted SMTP transports MUST use `encryption => 'none'` (STARTTLS-free, no AUTH). `CharSet` MUST stay `'UTF-8'` (PHPMailer 7 defaults to ISO-8859-1, which mojibakes non-ASCII subjects/bodies). HTML bodies MUST convert newlines via `nl2br()` and MUST NOT rely on the CSS `white-space` property: per Can I Email, `white-space` is only ~60% supported in mail clients (Outlook across all platforms, Gmail iOS/Android, Yahoo, GMX, Samsung all have partial/none support for `pre`/`pre-wrap`), so a `pre-wrap` wrapper collapses the message into one line for those users. `<br>` is universally supported and MUST be used for line breaks instead.

## 6. Sync Workflow (Deployment)
* **Mandatory Sync Order:** When the user requests a "sync" (deploying the current state to the live server via `./sync.sh`), the following order is REQUIRED:
  1. **Run the tests first.** Execute `composer test` (or `./vendor/bin/phpunit`). Do not proceed if the suite is failing.
  2. **Commit and push any open changes** before syncing. Do not sync with uncommitted/unpushed work left behind.
  3. **Only then run the sync** (`./sync.sh`).
  Rationale: a sync deploys to the live server; uncommitted work or broken tests must never reach production silently.