#!/usr/bin/env bash
# =============================================================================
# sync.sh — form2email via rsync/ssh auf root@reisinger.pictures spiegeln
# =============================================================================
#
# Ersetzt die frühere rclone-SFTP-Variante (Remote "reisinger.pictures").
# Hintergrund und Migrations-Reihenfolge: strato-vps/README.md
#
# ACHTUNG bei --delete: rclone sync war ein MIRROR. Die Excludes schützen
# ausgeschlossene Pfade VOR der Löschung — das ist beabsichtigt und muss
# erhalten bleiben. vendor/ und .codegraph/ liegen als Altlast auf dem Server
# und werden deshalb NICHT mit entfernt (siehe unten, "Bewusst nicht geloescht").
# =============================================================================
set -euo pipefail
cd "$(dirname "$0")"

# --- GNU-rsync-Pflicht -------------------------------------------------------
# macOS liefert per Default /usr/bin/rsync = openrsync (Protokoll 29), das weder
# --chown noch --chmod im benoetigten Umfang unterstuetzt.
# WICHTIG: nicht per `rsync --version | grep -q ...` pruefen. Unter `set -o
# pipefail` beendet `grep -q` den Upstream vorzeitig per SIGPIPE, die Pipeline
# gilt dann als fehlgeschlagen und der Guard schlaegt IMMER an. Deshalb die
# Ausgabe zuerst in eine Variable ziehen.
RSYNC_BIN="${RSYNC_BIN:-$(command -v rsync || true)}"
_rsync_version="$("$RSYNC_BIN" --version 2>/dev/null | head -1 || true)"
if [[ "$_rsync_version" != "rsync  version"* ]]; then
  echo "FEHLER: GNU-rsync benoetigt (macOS-Default ist openrsync)." >&2
  echo "       Install:  brew install rsync" >&2
  echo "       Oder:     RSYNC_BIN=/opt/homebrew/bin/rsync ./sync.sh" >&2
  exit 1
fi
unset _rsync_version

# --- Ziele ------------------------------------------------------------------
# Achtung: der SFTP-Chroot ist weg, daher der volle Pfad.
REMOTE="root@reisinger.pictures:/home/webadmin/websites/form.reisinger.pictures/"

# Connection-Reuse: verhindert bei ~2300 vendor-Dateien dutzende Handshakes.
SSH_OPTS="-o ControlMaster=auto -o ControlPath=/tmp/ssh-sync-%r@%h:%p -o ControlPersist=60"

# --chown/--chmod: Rechte-Modell der Sites ist 1002:webgroup, Dateien 666,
# Verzeichnisse 2777 (setgid). Ein reines -a wuerde die LOKALEN Rechte des
# Working Trees (644/755) durchdruecken und das Modell zerstoeren.
# D2777 = Verzeichnisse rwxrwsrwx, F666 = Dateien rw-rw-rw-.

# Excludes (Pfade am Repo-Wurzel-Anker "/", Verzeichnisse mit "/"):
#   rclone '/x/**'  ->  rsync '/x/'   (rsync steigt in ausgeschlossene
#                                        Verzeichnisse nicht hinab)
# Diese Liste entspricht 1:1 der bisherigen rclone-Liste. Bewusst NICHT
# ergaenzt: .DS_Store, .githooks/, repomix.config.json, server-diag.sh und
# .gitignore werden weiterhin hochgeladen (siehe remote). Das ist unnoetig,
# aber eine Aenderung des Deploy-Inhalts gehoert nicht in eine Transport-
# Migration. Kandidaten fuer ein spaeteres Aufraeumen.
# Secrets (.env*) duerfen NIE in den Webroot — eine .htaccess hilft unter
# PHP-FPM/Caddy nicht, siehe AGENTS.md §2.
echo "Synchronisiere form2email via rsync/ssh..."
"$RSYNC_BIN" ./ "$REMOTE" \
  --archive \
  --delete \
  --chown=1002:webgroup \
  --chmod=D2777,F666 \
  --info=progress2 \
  --exclude='/vendor/' \
  --exclude='/.git/' \
  --exclude='/.idea/' \
  --exclude='/.codegraph/' \
  --exclude='/.zcode/' \
  --exclude='/.phpunit.cache/' \
  --exclude='/.env*' \
  --exclude='/sync.sh' \
  --exclude='/repomix-form2email.md' \
  --exclude='/*.md' \
  --exclude='/tests/' \
  --exclude='/deployment/' \
  --rsh="ssh $SSH_OPTS"

echo "Upload fuer form.reisinger.pictures erfolgreich abgeschlossen!"
echo
echo "Hinweis: vendor/ und .codegraph/ bleiben auf dem Server als Altlast"
echo "         stehen (rsync --delete respectiert die Excludes). Sie sind"
echo "         nicht Teil des Deployments. Bewusstes Aufraeumen:"
echo "           ssh root@reisinger.pictures 'rm -rf /home/webadmin/websites/form.reisinger.pictures/{vendor,.codegraph}'"
