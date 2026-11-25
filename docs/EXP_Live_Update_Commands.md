# Pull and deploy an EXP application update

Use this for the existing live server at `10.10.20.51`, checkout `/var/www/exp-operations`, branch `main`, with the systemd units in [supervised](supervised/README.md). Run commands in order and stop at the first failure. This is an in-place deployment with a maintenance window; stopping the AMI listener creates a capture gap. Arrange a quiet window with operators before starting.

## Recovery commands for the failed 21 November update

The reported pull was blocked by seven modified tracked files, leaving the server at `0e8baa8`. The build also failed during Wayfinder generation. Press `q` first if the terminal shows `(END)`.

Complete and verify the database/environment/asset backup in the full guide before running this block. Review the release and production environment requirements below first. Run the entire block together: it uses a separate Bash process that stops at the first error, leaving the SSH session open. It only stashes tracked production changes when each modified file exactly matches `origin/main`. Unexpected differences or untracked files stop the deployment for review. The stash and patch remain available; do not reapply the stash after pulling.

```bash
bash <<'DEPLOY_COMMANDS'
set -Eeuo pipefail
trap 'echo "Deployment stopped at line $LINENO. Do not run later steps or reopen the app until the error is resolved." >&2' ERR
export GIT_PAGER=cat
cd /var/www/exp-operations

test "$(git branch --show-current)" = main
git fetch origin main
git merge-base --is-ancestor HEAD origin/main

DEPLOY_REVIEW="/var/backups/exp-operations/review-$(date -u +%Y%m%dT%H%M%SZ)"
install -d -m 700 "$DEPLOY_REVIEW"
umask 077
git diff --binary HEAD > "$DEPLOY_REVIEW/production-changes.patch"
git status --short > "$DEPLOY_REVIEW/git-status.txt"
git rev-parse HEAD > "$DEPLOY_REVIEW/previous-commit"

if [ -n "$(git ls-files --others --exclude-standard)" ]; then
    echo 'Untracked files require review before deployment.' >&2
    exit 1
fi

if ! git diff --quiet HEAD --; then
    while IFS= read -r -d '' deploy_file; do
        if ! git diff --quiet origin/main -- "$deploy_file"; then
            echo "Production changes differ from origin/main: $deploy_file" >&2
            git --no-pager diff origin/main -- "$deploy_file"
            exit 1
        fi
    done < <(git diff --name-only -z HEAD --)
    git stash push -m "Production changes preserved before deployment $(date -u +%Y%m%dT%H%M%SZ)"
    git rev-parse refs/stash > "$DEPLOY_REVIEW/stash-commit"
fi

test -z "$(git status --porcelain)"
php artisan down --retry=60
systemctl stop exp-scheduler.timer
systemctl stop exp-scheduler.service
systemctl stop exp-queue-worker.service exp-ami-listener.service

git pull --ff-only origin main
test "$(git rev-parse HEAD)" = "$(git rev-parse origin/main)"
git --no-pager log -1 --oneline
php artisan config:clear

# --no-dev deletes laravel/boost, so drop every discovery/cache manifest that was
# generated while it was installed (packages.php, services.php and any cached
# routes pointing at /_boost/*). Otherwise artisan aborts with
# `Class "Laravel\Boost\BoostServiceProvider" not found`.
rm -f bootstrap/cache/*.php
COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
php artisan wayfinder:generate --with-form
umask 022
npm ci --include=dev --no-audit --no-fund
npm run build
test -s public/build/manifest.json

php artisan migrate --force --no-interaction
php artisan migrate:status --no-interaction
php artisan optimize:clear
php artisan optimize
chown -R www-data:www-data storage bootstrap/cache
chmod -R ug+rwX storage bootstrap/cache

apache2ctl configtest
systemctl reload apache2
# If EXP uses PHP-FPM, uncomment after confirming its Apache handler:
# systemctl reload php8.3-fpm
systemctl enable --now exp-queue-worker.service exp-ami-listener.service exp-scheduler.timer
systemctl is-active --quiet exp-queue-worker.service
systemctl is-active --quiet exp-ami-listener.service
systemctl is-active --quiet exp-scheduler.timer
php artisan up

curl --fail --silent --show-error -o /dev/null -w 'Health: %{http_code}\n' http://10.10.20.51:8086/up
curl --fail --silent --show-error -o /dev/null -w 'Login: %{http_code}\n' http://10.10.20.51:8086/login
php artisan pbx:status
php artisan queue:failed
systemctl list-timers exp-scheduler.timer --all --no-pager
journalctl -u exp-queue-worker.service --since '10 minutes ago' --no-pager
git --no-pager log -1 --oneline
DEPLOY_COMMANDS
```

The `laravel/boost` failure mode is now confirmed on production (24 Nov 2026): `In Native.php line 202: Class "Laravel\Boost\BoostServiceProvider" not found`. `bootstrap/cache/packages.php`, `bootstrap/cache/services.php` and `bootstrap/cache/routes-v7.php` were generated while the dev dependency `laravel/boost` was installed; `composer install --no-dev` deletes the package but leaves those files, so the next `artisan` call cannot boot and, if the run aborts at that point, the site stays in maintenance mode with services stopped. `rm -f bootstrap/cache/*.php` before `composer install --no-dev` prevents it. If generation still fails, share that command's error output and keep the application in maintenance mode until repaired. If the block stops after `php artisan up`, return to maintenance mode if the verification failure affects service, and follow the recovery guide. Successful HTTP checks still require the email and PBX checks in section 6.

If SSH stops answering partway through a run while `http://10.10.20.51:8086/up` still responds, the host's firewall is rate-limiting the client address (repeat `ssh`/`scp` connections inside a minute are enough). Wait a few minutes and reconnect; do not assume the server rebooted, and do not start a second deploy in the meantime. If the console is reachable, clear the block with `fail2ban-client set sshd unbanip <client-ip>`.

The sections below describe the normal deployment stages. Do not continue by pasting later sections after this recovery block reports a failure.

## 1. Publish the release from development

The changes must be committed, pushed, and merged into remote `main` before the server can pull them. Uncommitted local changes are not deployed by `git pull`.

Review and validate the intended release locally using the [production deployment guide](EXP_Production_Deployment_Guide.md#1-prepare-and-publish-the-update-locally). Do not commit `.env` or credentials.

## 2. Connect and review the release

From your computer:

```bash
ssh root@10.10.20.51
```

On the server, in the same SSH session for all remaining steps:

```bash
cd /var/www/exp-operations
git status --short --branch
git branch --show-current
git fetch origin main
git log --oneline HEAD..origin/main
git diff --stat HEAD..origin/main
git diff --name-only HEAD..origin/main -- database/migrations .env.example composer.lock package-lock.json
php -v
node --version
systemctl status exp-queue-worker.service exp-ami-listener.service --no-pager
```

Confirm the checkout is clean and on `main`, PHP is 8.3, and Node is 22. Preserve and investigate unexpected production changes before proceeding. Review release migrations and environment requirements.

Before continuing, complete the [database, environment, and asset backup commands](EXP_Production_Deployment_Guide.md#3-back-up-the-database-environment-and-assets). Record the backup path and previous commit, and verify the database dump succeeded.

## 3. Pause EXP and pull the update

```bash
php artisan down --retry=60
systemctl stop exp-scheduler.timer
systemctl stop exp-scheduler.service
systemctl stop exp-queue-worker.service exp-ami-listener.service
git pull --ff-only origin main
git log -1 --oneline
php artisan config:clear
```

Wait for background services to stop before replacing dependencies. If the pull fails, resolve the cause before proceeding; do not use `git reset --hard` to discard server changes.

If required, update the existing production `.env` privately. Preserve `APP_KEY` and existing credentials. For onboarding emails, confirm the production `APP_URL` is reachable by recipients, mail transport settings are correct, and the queue connection matches the existing worker. Do not replace production `.env` with development settings or generate a new application key.

## 4. Install dependencies, build, and migrate

```bash
# `--no-dev` removes laravel/boost, so delete every cached manifest that was
# generated while it was installed - packages.php, services.php and any cached
# routes holding /_boost/* entries. Otherwise every artisan command aborts with
# `Class "Laravel\Boost\BoostServiceProvider" not found`.
rm -f bootstrap/cache/*.php
COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
php artisan wayfinder:generate --with-form
umask 022
npm ci --include=dev --no-audit --no-fund
npm run build
test -s public/build/manifest.json
php artisan migrate --force --no-interaction
php artisan migrate:status --no-interaction
php artisan optimize:clear
php artisan optimize
chown -R www-data:www-data storage bootstrap/cache
chmod -R ug+rwX storage bootstrap/cache
```

Confirm every command succeeded and no migrations remain pending. Keep the application in maintenance mode if any step fails.

The welcome, verification, and password setup email change requires no database seeding. Do not run the full `db:seed` during this routine update: it resets the seeded Super Administrator password and republishes reference configuration. Only run a targeted seeder if the specific release requires it.

## 5. Reload PHP and start EXP services

```bash
apache2ctl configtest
systemctl reload apache2
systemctl enable --now exp-queue-worker.service exp-ami-listener.service exp-scheduler.timer
systemctl is-active exp-queue-worker.service exp-ami-listener.service exp-scheduler.timer
php artisan up
```

If EXP uses PHP-FPM, also run `systemctl reload php8.3-fpm` before reopening the application. Confirm the Apache handler first; Apache and FPM are shared host services. The stopped queue worker and listener start fresh processes with the new code. If this release changes systemd unit files, follow [the supervision installation instructions](supervised/README.md#install-or-reproduce-the-setup) to install them and reload systemd before starting services; a Git pull alone does not update `/etc/systemd/system`.

## 6. Verify the release and email delivery

```bash
curl --fail --silent --show-error -o /dev/null -w 'Health: %{http_code}\n' http://10.10.20.51:8086/up
curl --fail --silent --show-error -o /dev/null -w 'Login: %{http_code}\n' http://10.10.20.51:8086/login
php artisan pbx:status
php artisan queue:failed
systemctl list-timers exp-scheduler.timer --all --no-pager
journalctl -u exp-queue-worker.service --since '10 minutes ago' --no-pager
journalctl -u exp-ami-listener.service --since '10 minutes ago' --no-pager
git log -1 --oneline
```

Expect both HTTP checks to return `200`. Confirm the AMI heartbeat advances and capture resumes. Check the browser for working sign-in, assets, and existing records.

Using an intended test recipient, create a user through the admin panel and confirm receipt of all three emails: welcome, verification, and password setup. Set the password, sign in, and verify the email address. A running queue worker is required for delivery; successful account creation alone does not prove emails were delivered. Expired links require a new password reset or verification request. Inspect failed jobs before retrying a specific job; do not blindly retry every historical failure.

Record the deployed commit, backup path, migrations, and verification results. If deployment or verification fails, keep or return the app to maintenance mode and follow the [recovery procedure](EXP_Production_Deployment_Guide.md#recovery-if-a-deployment-fails). A code rollback does not reverse database migrations.

Use the commands above for routine updates. The existing `docs/exp_deploy.sh` continues after errors and runs full seeding; see [its limitations](EXP_Production_Deployment_Guide.md#existing-deployment-script) before using it.
