#!/usr/bin/env bash
set -e
echo "Synchronisiere form2email via rclone..."
rclone sync . reisinger.pictures:/forms.reisinger.pictures \
  --transfers=50 \
  --track-renames \
  --progress \
  --exclude='/vendor/**' \
  --exclude='/.git/**' \
  --exclude='/.idea/**' \
  --exclude='/.phpunit.cache/**' \
  --exclude='/.zcode/**' \
  --exclude='/composer.lock' \
  --exclude='/sync.sh' \
  --exclude='/repomix-form2email.md'
echo "Upload fuer forms.reisinger.pictures erfolgreich abgeschlossen!"
