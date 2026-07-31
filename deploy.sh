#!/usr/bin/env bash
set -e

# Deploys the form2email application files to the production server.
#
# NOTE: The Docker stack itself is managed via Portainer Stacks (it pulls
# deployment/docker-compose.yml from the repository). This script therefore
# does NOT touch the containers - it only prepares the bind-mount directory
# that the stack uses, so after this script you must update the stack in
# Portainer to pick up the (new) volume path.
#
# The live container (form-reisinger-pictures) bind-mounts the directory
# named after the subdomain: /home/webadmin/form.reisinger.pictures -> /var/www/html
# This script:
#   1. Restores that directory if it was deleted and fixes its ownership so
#      rclone (running as webadmin) can write into it.
#   2. Re-syncs the repository via rclone (./sync.sh).
#   3. Re-installs the Composer dependencies in the live directory.
#   4. Reminds you to update the stack in Portainer and verifies the endpoint.
#
# Overridable via environment variables:
#   DEPLOY_SSH_HOST  (default: reisinger.pictures)
#   DEPLOY_SSH_PORT  (default: 22)
#   DEPLOY_SSH_USER  (default: root)

SSH_HOST="${DEPLOY_SSH_HOST:-reisinger.pictures}"
SSH_PORT="${DEPLOY_SSH_PORT:-22}"
SSH_USER="${DEPLOY_SSH_USER:-root}"
LIVE_DIR="/home/webadmin/form.reisinger.pictures"

SSH_DEST="${SSH_USER}@${SSH_HOST}"

echo "1/4 Stelle Live-Verzeichnis ${LIVE_DIR} wieder her (root)..."
ssh -p "${SSH_PORT}" "${SSH_DEST}" "
  mkdir -p ${LIVE_DIR}
  chown -R webadmin:webadmin ${LIVE_DIR}
  chmod -R u+rwX ${LIVE_DIR}
"

echo "2/4 Synchronisiere Code via rclone..."
./sync.sh

echo "3/4 Installiere Composer-Abhaengigkeiten in ${LIVE_DIR} (root)..."
ssh -p "${SSH_PORT}" "${SSH_DEST}" "
  cd ${LIVE_DIR}
  docker run --rm \
    --volume ${LIVE_DIR}:/app \
    --user \$(id -u webadmin):\$(id -g webadmin) \
    composer:latest update --ignore-platform-reqs
"

echo "4/4 Stack in Portainer aktualisieren..."
echo "  -> Oeffne Portainer, Stack auswaehlen, 'Pull and update' ausfuehren"
echo "     (uebernimmt das Volume /home/webadmin/form.reisinger.pictures)."
curl -s -o /dev/null -w "https://form.reisinger.pictures/ -> HTTP %{http_code}\n" https://form.reisinger.pictures/
echo "Deploy vorbereitet!"
