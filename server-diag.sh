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

ssh -p "${SSH_PORT}" "${SSH_USER}@${SSH_HOST}" '
  echo "=== docker ps ==="
  docker ps --format "table {{.Names}}\t{{.Image}}\t{{.Status}}"

  echo ""
  echo "=== Mounts of form-reisinger-pictures ==="
  docker inspect form-reisinger-pictures --format "{{range .Mounts}}{{.Type}} {{.Source}} -> {{.Destination}}{{println}}{{end}}"

  echo ""
  echo "=== compose-install.sh ==="
  cat /home/webadmin/compose-install.sh 2>/dev/null || echo "(nicht gefunden)"

  echo ""
  echo "=== /home/webadmin ==="
  ls -la /home/webadmin

  echo ""
  echo "=== index.php in Kandidaten-Verzeichnissen ==="
  for d in /home/webadmin/form.reisinger.pictures /home/webadmin/forms.reisinger.pictures /srv/websites/form.reisinger.pictures /srv/websites/forms.reisinger.pictures; do
    if [ -f "$d/index.php" ]; then
      echo "OK   $d/index.php ($(wc -c < "$d/index.php") bytes)"
    else
      echo "MISS $d/index.php"
    fi
  done
'
