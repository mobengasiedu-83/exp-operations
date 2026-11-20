#!/usr/bin/env bash
# Run on the production server after these files have been delivered to its checkout.
set -euo pipefail

APP_DIR=/var/www/exp-operations
PHP_BIN=/usr/bin/php8.3
SCRIPT_DIR=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)

if [[ "$EUID" -ne 0 ]]; then
    echo 'Run this installer as root.' >&2
    exit 1
fi
if [[ ! -f "$APP_DIR/artisan" || ! -x "$PHP_BIN" ]]; then
    echo 'Expected the production checkout and PHP 8.3 binary.' >&2
    exit 1
fi

# A systemd timer must not duplicate an existing cron scheduler for this checkout.
if grep -R -q -E '^[[:space:]]*[^#].*exp-operations.*schedule:run' \
    /etc/cron.d /etc/crontab /var/spool/cron/crontabs 2>/dev/null; then
    echo 'An EXP scheduler cron entry already exists. Reconcile it before installing the timer.' >&2
    exit 1
fi

units=(exp-ami-listener.service exp-scheduler.service exp-scheduler.timer exp-queue-worker.service)
for unit in "${units[@]}"; do
    test -r "$SCRIPT_DIR/$unit"
done
systemd-analyze verify "${units[@]/#/$SCRIPT_DIR/}"

BACKUP_DIR="/var/backups/exp-operations/supervision-$(date -u +%Y%m%dT%H%M%SZ)"
install -d -m 0700 "$BACKUP_DIR"
for unit in "${units[@]}"; do
    if [[ -f "/etc/systemd/system/$unit" ]]; then
        cp "/etc/systemd/system/$unit" "$BACKUP_DIR/previous-$unit"
    fi
    cp "$SCRIPT_DIR/$unit" "$BACKUP_DIR/$unit"
done

cd "$APP_DIR"
runuser -u www-data -- "$PHP_BIN" "$SCRIPT_DIR/enable-pbx.php"

for unit in "${units[@]}"; do
    install -o root -g root -m 0644 "$SCRIPT_DIR/$unit" "/etc/systemd/system/$unit"
done
systemctl daemon-reload
systemctl enable --now exp-ami-listener.service exp-scheduler.timer exp-queue-worker.service
# An existing running listener must load any changed unit settings as well.
systemctl restart exp-ami-listener.service exp-queue-worker.service
systemctl is-active exp-ami-listener.service exp-scheduler.timer exp-queue-worker.service
systemctl is-enabled exp-ami-listener.service exp-scheduler.timer exp-queue-worker.service
printf 'Previous unit files, when present: %s\n' "$BACKUP_DIR"
