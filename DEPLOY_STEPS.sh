#!/usr/bin/env bash
set -euo pipefail

APP_DIR="${1:-/var/www/id.imtsinstitute.com}"
PATCH_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

echo "===== IMTS ID CRM PHASE 1 DEPLOY ====="
echo "Target: $APP_DIR"

mkdir -p "$APP_DIR"
mkdir -p "$APP_DIR/storage/logs"

# Copy patch, preserving any future server-generated logs.
cp -a "$PATCH_DIR"/. "$APP_DIR"/

cd "$APP_DIR"

echo "===== PHP CHECK ====="
php -v | head -1
php -r 'if (PHP_VERSION_ID < 80300) { fwrite(STDERR,"PHP 8.3+ required\n"); exit(1);} echo "PHP 8.3+ OK\n";'

echo "===== PHP SYNTAX ====="
find app public -name '*.php' -print0 | xargs -0 -n1 php -l
php -l install.php
php -l sync.php

echo "===== INSTALL + FIRST SYNC ====="
php install.php

echo
echo "===== DEPLOY SUCCESS ====="
echo "Add this cron after verification:"
echo "* * * * * /usr/bin/php $APP_DIR/sync.php >> $APP_DIR/storage/logs/cron.log 2>&1"
