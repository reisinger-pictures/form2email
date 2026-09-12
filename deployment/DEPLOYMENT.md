# Deployment Guide (Portainer)

This document describes how to deploy the `form2email` application and its isolated token-checker sidecar using Portainer.

## Architecture

We use an **Infrastructure as Code (IaC)** approach. The `docker-compose.yml` file is stored in this Git repository. Portainer should pull the stack configuration directly from this repository to ensure a Single Source of Truth.

The stack consists of three services:
1. `composer_init`: A one-shot container that installs the Composer dependencies (`vendor/`) into the bind-mount directory before the app starts. The `app` service starts only after it completed successfully.
2. `app`: The live PHP web application serving the form.
3. `token-checker`: An isolated Alpine Linux container that proactively monitors the validity of the Google OAuth2 token and alerts the administrator via Make.com if it expires.

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

#### Common (only required while using the `token-checker` sidecar)

| Variable Name | Description | Example / Format |
| :--- | :--- | :--- |
| `MAKE_WEBHOOK_URL` | The URL provided by your Make.com Webhook trigger. Used ONLY by the `token-checker` sidecar (Gmail/OAuth2 token monitoring, API path). The live app never calls Make.com. | `https://hook.eu1.make.com/...` |
| `MAKE_API_KEY` | Your Make.com authentication header key (used only by the `token-checker` sidecar). | `VUzRWb8yWw-JFTc` |

#### Required when `auth_type=oauth2` (Google XOAUTH2) for a domain

| Variable Name | Description | Example / Format |
| :--- | :--- | :--- |
| `<PREFIX>_OAUTH_CLIENT_ID` | Google OAuth2 Client ID. | `...apps.googleusercontent.com` |
| `<PREFIX>_OAUTH_CLIENT_SECRET` | Google OAuth2 Client Secret. | `GOCSPX-...` |
| `<PREFIX>_OAUTH_REFRESH_TOKEN` | Google OAuth2 Refresh Token. | `1//03a6...` |

#### Required when `auth_type=password` (SMTP/Basic Auth) for a domain

| Variable Name | Description | Example / Format |
| :--- | :--- | :--- |
| `<PREFIX>_SMTP_PASSWORD` | SMTP password for the configured username. | `your-smtp-password` |
| `<PREFIX>_SMTP_HOST` | SMTP server hostname (optional, has sample default). | `smtp.example.com` |
| `<PREFIX>_SMTP_PORT` | SMTP server port (optional, defaults to `587`). | `587` |
| `<PREFIX>_SMTP_ENCRYPTION` | Encryption mode: `tls` or `ssl` (optional, defaults to `tls`). | `tls` |
| `<PREFIX>_SMTP_USERNAME` | SMTP username (optional, falls back to config.php). | `contact@example.com` |

Example for the `reisinger.pictures` domain (oauth2): `REISINGER_PICTURES_OAUTH_CLIENT_ID`,
`REISINGER_PICTURES_OAUTH_CLIENT_SECRET`, `REISINGER_PICTURES_OAUTH_REFRESH_TOKEN`.

> **Note:** When switching a domain from `oauth2` to `password`, the
> `token-checker` sidecar container no longer serves a purpose and can be
> disabled in the stack without affecting the live `app` container.

7. Toggle **Enable relative path volumes** (if applicable to your Portainer setup) to ensure the volume binds work correctly.
8. Click **Deploy the stack**.

## Updating the Stack

When you make changes to the `docker-compose.yml` in this repository:
1. Go to the Stack in Portainer.
2. Click on the **Editor** tab.
3. Click **Pull and update MAC** (or manually click "Update the stack" if you used the Web editor method).