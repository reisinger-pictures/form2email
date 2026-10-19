# Architecture & Design Decisions (Background)

This document holds the *why* behind the rules in `AGENTS.md`: permanent
decisions, rationales, measurements and history. Rules themselves live in
`AGENTS.md`; open work in `AGENTS.todo.md`. Operators looking for deployment
background should also read `deployment/DEPLOYMENT.md`.

## Configuration single source of truth

The non-secret mail defaults (host, port, encryption, username, sender) live
only in `config.php`, and `docker-compose.yml` forwards the matching variables
empty (`${VARIABLE_NAME:-}`). The reason is drift: two parallel default sets
meant a stale provider endpoint could silently win. This actually happened —
leftover **ZeptoMail defaults overrode the Zoho Mail config**, producing a
`Could not authenticate` failure. That is why duplicated defaults are forbidden
and why secrets keep no default at all (see `deployment/DEPLOYMENT.md`).

## Request modes (`_next` vs Pure POST/API)

`index.php` was deliberately kept to exactly two request modes and no
fallback. A missing `_next` is *not* an error; it selects the pure POST/API
JSON mode, which is the mode the JS/fetch clients use. Treating the absence of
`_next` as an error would break those API callers.

## No external error webhooks

An earlier design shipped failures to an external automation webhook
(e.g. Make.com). It was removed because it exported form/error payloads to a
third party and made the send path depend on an external service. Operational
visibility now comes from the structured, secret-free `error_log` line instead.

## Health checks belong in a sidecar

Validating external connections (e.g. whether an OAuth token is still valid)
inside the request path would couple live traffic to a slow/failing probe and
could mutate request state. Keeping it in an isolated sidecar keeps the web
application a pure request handler.

## Email-client compatibility measurement

The HTML-body rules (`nl2br()` instead of CSS `white-space`) are backed by a
*Can I Email* check: `white-space` is only referenced as ~60% supported; Outlook
(on all platforms), Gmail (iOS/Android), Yahoo, GMX and Samsung all have
partial or no support for `pre`/`pre-wrap`. A `pre-wrap` wrapper therefore
collapses the message into a single line for those users, while `<br>` is
universally supported.

## Failover-test rationale

The forced-failure test after mailer changes exists because the failure handler
regressed silently before — the code caught the wrong `Exception` class, so the
client got no proper HTTP 500 JSON response. Only an end-to-end forced failure
catches that class of regression.

## Rate-limiting rationale

* Counting raw POSTs instead of actual send attempts lets bots that fail
  validation exhaust a client's quota and lock out legitimate senders — hence
  the limiter runs *after* validation.
* Falling back to `REMOTE_ADDR` behind a proxy would key every visitor on the
  proxy container IP and rate-limit them as one client — hence the limiter must
  fail OPEN when the trusted client-IP header is missing/invalid.
* A broken file cache must never block mail delivery, so storage/lock errors
  fail open.

## Agent working-style rationale

* **Complete code output:** outputting whole files instead of diffs prevents
  copy-paste errors and ensures the user always has a fully deployable file.
* **PHPDoc:** never trimming existing PHPDoc keeps the generated API docs and
  IDE hints intact.
