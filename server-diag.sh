#!/usr/bin/env bash
set -e

# Connects to the production server as root via SSH and prints diagnostics
# about the running form container and the deployed files.
#
# Overridable via environment variables:
#   DEPLOY_SSH_HOST  (default: reisinger.pictures)
#   DEPLOY_SSH_PORT  (default: 22)
#   DEPLOY_SSH_USER  (default: root)

SSH_HOST="${DEPLOY_SSH_HOST:-reisinger.pictures}"
SSH_PORT="${DEPLOY_SSH_PORT:-22}"
SSH_USER="${DEPLOY_SSH_USER:-root}"
LIVE_DIR="/home/webadmin/websites/form.reisinger.pictures"

ssh -p "${SSH_PORT}" "${SSH_USER}@${SSH_HOST}" "
  echo '=== docker ps ==='
  docker ps --format 'table {{.Names}}\t{{.Image}}\t{{.Status}}'

  echo ''
  echo '=== Mounts of form-reisinger-pictures ==='
  docker inspect form-reisinger-pictures --format '{{range .Mounts}}{{.Type}} {{.Source}} -> {{.Destination}}{{println}}{{end}}'

  echo ''
  echo '=== docker exec: Inhalt /var/www/html im Container ==='
  docker exec form-reisinger-pictures ls -la /var/www/html 2>/dev/null || echo '(Container nicht erreichbar)'

  echo ''
  echo '=== webadmin Home-Verzeichnis (getent passwd) ==='
  getent passwd webadmin

  echo ''
  echo '=== Live-Verzeichnis ${LIVE_DIR} (root-Sicht) ==='
  ls -la ${LIVE_DIR}

  echo ''
  echo '=== vendor/ vorhanden? ==='
  if [ -d ${LIVE_DIR}/vendor ]; then
    echo "JA - $(find ${LIVE_DIR}/vendor -type f | wc -l) Dateien"
  else
    echo 'NEIN (Composer-Install erforderlich)'
  fi

  echo ''
  echo '=== Docker-Mount-Sicht: composer:latest auf /app ==='
  docker run --rm --volume ${LIVE_DIR}:/app composer:latest ls -la /app

  echo ''
  echo '=== Suche nach index.php in *reisinger.pictures* ==='
  find /home /srv /var -maxdepth 5 -path '*reisinger.pictures/index.php' 2>/dev/null
"
