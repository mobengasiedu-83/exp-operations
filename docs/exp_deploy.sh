#!/usr/bin/env bash
#
# EXP Operations Console — deploy the latest `main` and republish reference data.
#
# Usage (on the application server, as root or the user that owns the checkout):
#
#   bash docs/exp_deploy.sh
#   APP_DIR=/var/www/exp-operations APP_URL=http://10.10.20.51:8086 bash docs/exp_deploy.sh
#
# Optional overrides:
#   APP_DIR          checkout to deploy                  (default /var/www/exp-operations)
#   APP_URL          base URL for the health check       (default http://127.0.0.1:8086)
#   BACKUP_DIR       where the pre-deploy dump is written (default /var/backups)
#   APACHE_SERVICE   service reloaded at the end          (default apache2)
#   SKIP_BACKUP=1    skip the mysqldump step
#   SKIP_BUILD=1     skip `npm run build` (only when no frontend file changed)
#
# Steps: pre-flight -> database backup -> git pull --ff-only -> composer install ->
# npm run build -> migrate --force -> db:seed --force -> rebuild caches -> storage
# permissions + graceful Apache reload -> verification.
#
# The script deliberately does not use `set -e`: each step prints its own exit
# code, so one failing verification cannot hide the remaining output.
#
# `php artisan db:seed` republishes the role matrix and the super administrator
# password from database/seeders/SuperAdministratorSeeder.php. It never deletes
# user-generated records, but rotate that password after the first login and keep
# the seeder constants in step with it.

set -uo pipefail

APP_DIR="${APP_DIR:-/var/www/exp-operations}"
APP_URL="${APP_URL:-http://127.0.0.1:8086}"
BACKUP_DIR="${BACKUP_DIR:-/var/backups}"
APACHE_SERVICE="${APACHE_SERVICE:-apache2}"

cd "$APP_DIR" || { echo "cannot cd $APP_DIR"; exit 1; }

section() { printf '\n===== %s =====\n' "$1"; }

# Values printed by tinker into a variable are never echoed, so credentials stay
# out of the deploy log. Never parse .env directly: APP_KEY=base64:...= breaks
# parse_ini_file.
artisan_value() {
    php artisan tinker --execute "echo $1;" 2>/dev/null | tr -d '\r\n'
}

# mysql helper: db_scalar "select count(*) from users"
db_scalar() {
    mysql -u"$DB_USER" -N -B "$DB_NAME" -e "$1" 2>/dev/null
}

counts() {
    printf 'users=%s roles=%s permissions=%s dentists=%s reports=%s\n' \
        "$(db_scalar 'select count(*) from users')" \
        "$(db_scalar 'select count(*) from roles')" \
        "$(db_scalar 'select count(*) from permissions')" \
        "$(db_scalar 'select count(*) from dentists')" \
        "$(db_scalar 'select count(*) from report_definitions')"
}

section "0. pre-flight"
date -u
printf 'app_dir=%s url=%s\n' "$APP_DIR" "$APP_URL"
git --no-pager log --oneline -1
git status --short --branch
php artisan --version

section "1. database backup"
DB_USER=$(artisan_value 'config("database.connections.mysql.username")')
DB_PASS=$(artisan_value 'config("database.connections.mysql.password")')
DB_NAME=$(artisan_value 'config("database.connections.mysql.database")')
export MYSQL_PWD="$DB_PASS"

if [[ "${SKIP_BACKUP:-0}" == "1" ]]; then
    printf 'backup=skipped\n'
else
    mkdir -p "$BACKUP_DIR"
    BACKUP="$BACKUP_DIR/${DB_NAME}-$(date +%Y%m%d-%H%M%S).sql"
    if mysqldump --single-transaction --quick -u"$DB_USER" "$DB_NAME" > "$BACKUP" 2>/tmp/exp_dump.err; then
        printf 'backup=%s size=%s tables=%s\n' "$BACKUP" "$(du -h "$BACKUP" | cut -f1)" "$(grep -c 'CREATE TABLE' "$BACKUP")"
    else
        printf 'backup=FAILED %s\n' "$(tail -2 /tmp/exp_dump.err)"
    fi
fi
printf 'pre-deploy   '; counts

section "2. git pull origin main"
git config core.fileMode false
git fetch origin --prune
git pull --ff-only origin main
printf 'pull_exit=%s head=%s\n' "$?" "$(git rev-parse --short HEAD)"
git --no-pager log --oneline -3

section "3. composer install"
composer install --no-interaction --prefer-dist --optimize-autoloader 2>&1 | tail -12
printf 'composer_exit=%s\n' "${PIPESTATUS[0]}"

section "4. frontend build"
if [[ "${SKIP_BUILD:-0}" == "1" ]]; then
    printf 'build=skipped\n'
else
    npm run build 2>&1 | tail -15
    printf 'build_exit=%s\n' "${PIPESTATUS[0]}"
    ls -1 public/build/manifest.json >/dev/null 2>&1 \
        && stat -c 'manifest_mtime=%y' public/build/manifest.json
fi

section "5. migrations"
php artisan migrate --force --no-interaction 2>&1 | tail -15
printf 'migrate_exit=%s pending=%s\n' \
    "${PIPESTATUS[0]}" \
    "$(php artisan migrate:status --no-interaction 2>/dev/null | grep -c Pending)"

section "6. seed database"
php artisan db:seed --force --no-interaction 2>&1 | tail -30
printf 'seed_exit=%s\n' "${PIPESTATUS[0]}"

section "7. rebuild caches"
php artisan optimize:clear 2>&1 | tail -4
php artisan optimize 2>&1 | tail -8
printf 'optimize_exit=%s\n' "$?"

section "8. permissions and opcache"
if id www-data >/dev/null 2>&1; then
    chown -R www-data:www-data storage bootstrap/cache
    chmod -R ug+rwX storage bootstrap/cache
fi
if systemctl is-active --quiet "$APACHE_SERVICE"; then
    systemctl reload "$APACHE_SERVICE" && printf 'apache_reload=ok\n' || printf 'apache_reload=FAILED\n'
    systemctl is-active "$APACHE_SERVICE"
else
    printf 'apache_reload=skipped (%s not active)\n' "$APACHE_SERVICE"
fi

section "9. verify"
ADMIN_EMAIL=$(artisan_value 'Database\Seeders\SuperAdministratorSeeder::EMAIL')
ADMIN_PASSWORD=$(artisan_value 'Database\Seeders\SuperAdministratorSeeder::PASSWORD')

printf 'commit=%s\n' "$(git rev-parse --short HEAD)"
printf 'post-seed    '; counts

php artisan tinker --execute "
    \$admin = App\Models\User::query()->where('email', '${ADMIN_EMAIL}')->first();
    echo 'admin='.(\$admin ? 'found' : 'MISSING')
        .' hash_ok='.(\$admin && Illuminate\Support\Facades\Hash::check('${ADMIN_PASSWORD}', (string) \$admin->password) ? 'yes' : 'no')
        .' active='.(\$admin?->active ? '1' : '0')
        .' role='.(\$admin ? \$admin->getRoleNames()->implode(',') : '-')
        .PHP_EOL;" 2>&1 | tail -3

printf 'login_page=%s\n' "$(curl -s -o /dev/null -w '%{http_code}' "$APP_URL/login")"

COOKIES=$(mktemp)
trap 'rm -f "$COOKIES"' EXIT
curl -s -c "$COOKIES" -o /dev/null "$APP_URL/login"
XSRF=$(awk '/XSRF-TOKEN/ {print $NF}' "$COOKIES" | tail -1)
XSRF=$(printf '%b' "${XSRF//%/\\x}")
printf 'login_post=%s\n' "$(curl -s -b "$COOKIES" -c "$COOKIES" -o /dev/null -w '%{http_code}' \
    -X POST "$APP_URL/login" \
    -H "X-XSRF-TOKEN: $XSRF" \
    -H 'Content-Type: application/json' \
    -H 'Accept: application/json' \
    -H 'X-Requested-With: XMLHttpRequest' \
    --data "{\"email\":\"${ADMIN_EMAIL}\",\"password\":\"${ADMIN_PASSWORD}\"}")"
printf 'dashboard=%s\n' "$(curl -s -b "$COOKIES" -o /dev/null -w '%{http_code}' "$APP_URL/dashboard")"

section "done"
date -u
