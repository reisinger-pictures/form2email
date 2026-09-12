#!/usr/bin/env bash
set -e
echo "Synchronisiere form2email via rclone..."
rclone sync . reisinger.pictures:/form.reisinger.pictures \
  --transfers=50 \
  --track-renames \
  --progress \
  --exclude='/vendor/**' \
  --exclude='/.git/**' \
  --exclude='/.idea/**' \
  --exclude='/.env*' \
  --exclude='/.phpunit.cache/**' \
  --exclude='/.zcode/**' \
  --exclude='/.codegraph/**' \
  --exclude='/sync.sh' \
  --exclude='/deploy.sh' \
  --exclude='/repomix-form2email.md' \
  --exclude='/*.md' \
  --exclude='/tests/**' \
  --exclude='/deployment/**'
echo "Upload fuer form.reisinger.pictures erfolgreich abgeschlossen!"
