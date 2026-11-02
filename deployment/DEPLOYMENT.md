# Deployment

## Architecture

The Compose file is version-controlled in this repository. `deployment/deploy.sh`
transfers only this file to `/opt/stacks/form-reisinger-pictures/compose.yaml`
and updates the stack with Docker Compose. Portainer is no longer part of the
deployment workflow. Background: `strato-vps/docs/plans/portainer-exit.md`.

The stack consists of two services:

1. `composer_init`: A one-shot container that installs the Composer dependencies
   (`vendor/`) in the bind-mounted directory before the app starts.
2. `app`: The PHP web application. It starts only after `composer_init` has
   completed successfully.

The mail transport is selected per domain in `config.php`. For the production
domain `reisinger.pictures`, Zoho Mail is used over STARTTLS
(`smtppro.zoho.eu:587`) with an SMTP password.

### Configuration model (Single Source of Truth)

To prevent configuration drift, the Compose file, `config.php`, and the
host-side `.env` are clearly separated:

* **Non-secret defaults live exclusively in `config.php`** (host, port,
  encryption, username, sender). Compose forwards optional environment
  variables as empty (`${VARIABLE_NAME:-}`), keeping `config.php` as the only
  source of these defaults.
* **The only production secret is** `REISINGER_PICTURES_SMTP_PASSWORD`. It has
  no default. If it is empty, `mailer_phpmailer.php` fails with a clear,
  secret-free error.
* **The `.env` exists only on the host** at
  `/opt/stacks/form-reisinger-pictures/.env`, is owned by `root:root`, and has
  mode `0600`. It is never created from the repository, overwritten, or synced.
  It is outside the web root.

## Deploy

Before the first deployment, create the stack directory on the host and manage
the secret file there manually. Example contents for the file—replace the
placeholder with the secret and never commit it:

```dotenv
REISINGER_PICTURES_SMTP_PASSWORD=<SMTP app password>
```

The file must have mode `0600`. The deployment script checks the directory,
file, and permissions, but does not read or print the secret contents.

```bash
./deployment/deploy.sh --dry-run
./deployment/deploy.sh
```

The script transfers only `deployment/docker-compose.yml` to
`/opt/stacks/form-reisinger-pictures/compose.yaml` and runs
`docker compose -f /opt/stacks/form-reisinger-pictures/compose.yaml up -d --wait`
from the stack directory. It runs neither `down` nor `--build` and does not
touch the `.env` file.

### Environment variables

For the production domain, only the SMTP password is required. Host, port,
encryption, and username are optional overrides and remain empty in the Compose
file when unset:

| Variable | Meaning | Default |
| :--- | :--- | :--- |
| `REISINGER_PICTURES_SMTP_PASSWORD` | **Secret**, Zoho app password; no default | Required |
| `REISINGER_PICTURES_SMTP_HOST` | Optional SMTP host override | `config.php` |
| `REISINGER_PICTURES_SMTP_PORT` | Optional SMTP port override | `config.php` |
| `REISINGER_PICTURES_SMTP_ENCRYPTION` | Optional override (`tls`/`ssl`) | `config.php` |
| `REISINGER_PICTURES_SMTP_USERNAME` | Optional SMTP username override | `config.php` |

Zoho Mail uses the full mailbox name and an **app password** (Zoho account →
Security → App Passwords), not the regular account password.

## Updating the Compose file

Stack changes are deployed with `./deployment/deploy.sh`. The script uses the
project directory `/opt/stacks/form-reisinger-pictures`, allowing Docker Compose
to load the `.env` file there. Application files (`config.php`, `index.php`,
`src/**`, mailers) continue to be synced separately with `./sync.sh`.

## Troubleshooting

On failure, the mailer writes a structured, secret-free log line:

```
form2email mail send failed: mailer=phpmailer auth=password host=smtppro.zoho.eu
port=587 encryption=tls username=florian@reisinger.pictures from=...
receiver=... error="SMTP Error: Could not authenticate."
exception=PHPMailer\PHPMailer\Exception hint="SMTP authentication failed: ..."
```

* Logs: `docker logs form-reisinger-pictures`. The SMTP password is not logged.
* Check file permissions without reading the secret contents:
  `stat -c '%a %U:%G' /opt/stacks/form-reisinger-pictures/.env` — expected:
  `600 root:root`.
* `Could not authenticate` usually means the username does not match the host or
  the app password has been revoked. Add a new app password to the host `.env`;
  do not copy its contents into logs or terminal output.
* An empty password is explicitly reported as “SMTP password is not configured”.
