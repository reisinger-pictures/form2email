#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
COMPOSE_FILE="$SCRIPT_DIR/docker-compose.yml"
SSH_TARGET="root@reisinger.pictures"
STACK_DIR="/opt/stacks/form-reisinger-pictures"
REMOTE_COMPOSE="$STACK_DIR/compose.yaml"
CONTROL_PATH="/tmp/ssh-sync-%r@%h:%p"
SSH_OPTS=(-o ControlMaster=auto -o "ControlPath=$CONTROL_PATH" -o ControlPersist=60)
RSYNC_RSH="ssh ${SSH_OPTS[*]}"

DRY_RUN=false
case "${1:-}" in
  "") ;;
  --dry-run) DRY_RUN=true ;;
  *)
    printf 'Usage: %s [--dry-run]\n' "$0" >&2
    exit 2
    ;;
esac

if [[ $# -gt 1 ]]; then
  printf 'Usage: %s [--dry-run]\n' "$0" >&2
  exit 2
fi

[[ -f "$COMPOSE_FILE" ]] || {
  printf 'Compose file not found: %s\n' "$COMPOSE_FILE" >&2
  exit 1
}

REMOTE_PREFLIGHT="if [ ! -d '$STACK_DIR' ]; then echo 'Stack directory not found: $STACK_DIR' >&2; exit 1; fi; if [ ! -f '$STACK_DIR/.env' ]; then echo 'Host secret file not found: $STACK_DIR/.env' >&2; exit 1; fi; meta=\$(stat -c '%a:%U:%G' '$STACK_DIR/.env'); if [ \"\$meta\" != '600:root:root' ]; then echo 'Host secret file must be owned by root:root and have mode 0600' >&2; exit 1; fi"
REMOTE_UP="cd '$STACK_DIR' && docker compose -f '$REMOTE_COMPOSE' up -d --wait"

print_command() {
  printf '  '
  printf '%q ' "$@"
  printf '\n'
}

if [[ "$DRY_RUN" == true ]]; then
  printf 'Dry run: no connection or changes to the host. Planned commands:\n'
  print_command ssh "${SSH_OPTS[@]}" -T "$SSH_TARGET" "$REMOTE_PREFLIGHT"
  print_command rsync --archive --no-owner --no-group --rsh="$RSYNC_RSH" -- "$COMPOSE_FILE" "$SSH_TARGET:$REMOTE_COMPOSE"
  print_command ssh "${SSH_OPTS[@]}" -T "$SSH_TARGET" "$REMOTE_UP"
  exit 0
fi

printf 'Checking stack directory and permissions of the host .env on %s ...\n' "$SSH_TARGET"
ssh "${SSH_OPTS[@]}" -T "$SSH_TARGET" "$REMOTE_PREFLIGHT"

printf 'Syncing only the Compose file; the SMTP secret will remain untouched ...\n'
rsync --archive --no-owner --no-group --rsh="$RSYNC_RSH" -- "$COMPOSE_FILE" "$SSH_TARGET:$REMOTE_COMPOSE"

printf 'Updating stack %s ...\n' "$STACK_DIR"
ssh "${SSH_OPTS[@]}" -T "$SSH_TARGET" "$REMOTE_UP"

printf 'Deployment complete.\n'
