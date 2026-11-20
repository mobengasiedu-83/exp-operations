# EXP production deployment guide

Use this procedure to deploy an approved update from GitHub branch `main` to the existing production application. Run the server steps in order, in the same SSH session. Stop at the first error; do not continue to seeding, service reloads or reopening the application after a failed step.

| Item | Production value |
|---|---|
| SSH server | `10.10.20.51` |
| Current SSH account | `root` |
| Application directory | `/var/www/exp-operations` |
| Git branch | `main` |
| Application address | `http://10.10.20.51:8086` |
| Web server | Apache, service `apache2` |
| PHP | 8.3 |
| Node.js | 22 |
| Database | MariaDB, using the application’s configured MySQL connection |
| Web process account | `www-data` |

Deploy outside the staffed window of Monday–Friday, 10:00–14:00 Ghana time, whenever possible. Tell operators when the maintenance window starts and finishes. Keep SSH passwords, database passwords, `.env` and backups out of Git and shared logs.

## 1. Prepare and publish the update locally

On the development computer, review your changes and run:

```bash
php artisan test --compact
npm run build
npm run types:check
git status --short
```

Run the build before the final type check because the build generates Wayfinder route types. Run tests and the build sequentially: the build replaces asset files that some feature tests render.

Commit the intended files to a feature branch, push it, and merge the reviewed change into `main` through your normal repository process. Confirm the checks passed for the commit being released. A server-side pull does not transfer uncommitted work from your computer.

Do not commit `.env`, credentials, production exports or `node_modules`/`vendor`.

## 2. Connect to production

From your computer:

```bash
ssh root@10.10.20.51
```

Enter the current SSH password at the prompt. In the server session:

```bash
cd /var/www/exp-operations
pwd
git status --short --branch
git branch --show-current
php -v
composer --version
node --version
npm --version
```

The checkout must be on `main`, and `git status --short` must be empty. If files are modified or untracked, investigate and preserve them before continuing. Do not discard production changes or use `git reset --hard` to make a pull succeed.

Fetch and review the release before changing the running application:

```bash
git fetch origin main
git log --oneline HEAD..origin/main
git diff --stat HEAD..origin/main
git diff --name-only HEAD..origin/main -- database/migrations database/seeders .env.example composer.lock package-lock.json
```

Review any new migrations, seeder changes and environment variables in that release. If the branches have diverged, stop and resolve the release through the repository; do not create an accidental merge on production.

## 3. Back up the database, environment and assets

Keep this SSH session open so the variables remain available for recovery:

```bash
DEPLOY_APP=/var/www/exp-operations
DEPLOY_BACKUP="/var/backups/exp-operations/$(date -u +%Y%m%dT%H%M%SZ)"
DEPLOY_PREVIOUS_COMMIT=$(git rev-parse HEAD)

umask 077
mkdir -p "$DEPLOY_BACKUP"
chmod 700 "$DEPLOY_BACKUP"
printf '%s\n' "$DEPLOY_PREVIOUS_COMMIT" > "$DEPLOY_BACKUP/previous-commit"
cp .env "$DEPLOY_BACKUP/environment"
cp composer.lock package-lock.json "$DEPLOY_BACKUP/"
tar -czf "$DEPLOY_BACKUP/assets-and-cache.tar.gz" public/build bootstrap/cache
```

Read the production `DB_HOST`, `DB_DATABASE` and `DB_USERNAME` settings privately from `.env` (or the deployment-managed configuration). Do not paste the full environment into shared logs. Set the following three values to the database connection actually used by this application. Replace the example placeholders before running the dump. Use the database password at the prompt, not the SSH password. If the connection uses a non-default port, socket or TLS, supply the corresponding client options too.

```bash
DEPLOY_DB_HOST=localhost
DEPLOY_DB_NAME=YOUR_APPLICATION_DATABASE
DEPLOY_DB_USER=YOUR_DATABASE_USER

mysqldump \
  --host="$DEPLOY_DB_HOST" \
  --user="$DEPLOY_DB_USER" \
  --password \
  --single-transaction --quick --no-tablespaces --routines --events \
  "$DEPLOY_DB_NAME" > "$DEPLOY_BACKUP/database.sql"
```

A successful dump must exit with code `0` and contain the schema and data. Check immediately:

```bash
echo "Dump exit code: $?"
test -s "$DEPLOY_BACKUP/database.sql"
grep -c 'CREATE TABLE' "$DEPLOY_BACKUP/database.sql"
ls -lh "$DEPLOY_BACKUP"
```

Stop if the dump failed, is empty, or contains no expected application tables. Keep the backup directory private and retain a protected copy off the application server. Record the backup path and previous commit in the release record.

## 4. Enter maintenance mode and pull main

```bash
php artisan down --retry=60
git pull --ff-only origin main
git log -1 --oneline
php artisan config:clear
```

Maintenance mode applies to EXP, not the other applications hosted on this server. Keep the application in maintenance mode until all required deployment steps succeed.

If the release needs new environment values, edit the existing production `.env` now:

```bash
nano .env
```

Preserve the existing database credentials, AMI credentials and `APP_KEY`. Do not copy the development `.env` over production or run `php artisan key:generate` on this existing installation: changing the key would affect encrypted caller data and other encrypted values.

## 5. Install the locked PHP dependencies

As the current root deployment account:

```bash
COMPOSER_ALLOW_SUPERUSER=1 composer install \
  --no-dev --no-interaction --prefer-dist --optimize-autoloader
```

Use `composer install`, which follows `composer.lock`. Do not run `composer update` as part of a production release.

## 6. Install frontend dependencies and build

```bash
umask 022
npm ci --include=dev --no-audit --no-fund
npm run build
test -s public/build/manifest.json
```

The development dependencies are needed for the TypeScript/Vite build tools even though the generated assets are for production. `npm ci` follows the committed lock file.

If installation or compilation fails, stop and inspect the error. Do not reopen the site with missing assets. A development server (`npm run dev`) is not the production asset server.

## 7. Apply database migrations

```bash
php artisan migrate --force --no-interaction
php artisan migrate:status --no-interaction
```

Confirm that no application migrations remain pending. Do not use `migrate:fresh`, `migrate:refresh`, `db:wipe` or destructive reset commands on production.

## 8. Seed only when the release calls for it

A code-only release normally does not need the complete database seed. Decide which seed operation is intended before running it.

### Complete reference-data refresh

For a release intentionally refreshing the full seeded configuration:

```bash
php artisan db:seed --force --no-interaction
```

The current full seed has these concrete effects:

- Republishes the role and permission matrix, including synchronising grants for seeded roles.
- Activates the seeded questionnaire version. Check the intended active questionnaire afterwards, especially if administrators have published a newer version.
- Adds missing seeded dentists and numbers, and standard roster windows for the current and next service week.
- Publishes reports, guides and other reference data.
- Resets the seeded Super Administrator password to the value in `database/seeders/SuperAdministratorSeeder.php`.

Do not treat this as a password-preserving operation. If preserving the current administrator password or custom reference data is required, use a reviewed targeted seeder or a release-specific migration instead of the full seed. Rotating the administrator password afterwards is not durable if a later full seed resets it again; account for the seeder behavior in the password-management process.

The seed is not intended to remove call history. Afterward, verify that existing users, cases, answers and notes remain present and that role grants and roster entries are appropriate.

### Targeted refresh example

For a release updating only guide access:

```bash
php artisan db:seed --class=GuideAccessSeeder --force --no-interaction
```

This also synchronises the Viewer role’s guide/read permissions; it is not a content-only action. Choose seeders by their actual effects, not just their names.

## 9. Rebuild caches and restore writable-directory ownership

```bash
php artisan optimize:clear
php artisan optimize
chown -R www-data:www-data storage bootstrap/cache
chmod -R ug+rwX storage bootstrap/cache
```

`optimize:clear` also clears the application’s default cache. Use it deliberately during the maintenance window, then rebuild caches. Check that `optimize` exits successfully.

Only the writable directories above need this ownership adjustment. Do not make the entire checkout world-writable or apply `chmod -R 777`.

## 10. Refresh EXP processes and the web server

Long-running processes need to load the new code and configuration. First identify processes belonging to `/var/www/exp-operations`; this host also runs other applications.

For EXP’s supervised standard queue workers, when configured:

```bash
php artisan queue:restart
```

This asks workers to exit after finishing their current job. Their process manager must restart them. Do not start a duplicate worker manually.

For the EXP AMI listener, restart its **actual configured service**. Example only, after verifying that this unit exists and belongs to EXP:

```bash
systemctl cat exp-ami-listener.service
systemctl restart exp-ami-listener.service
systemctl status exp-ami-listener.service --no-pager
```

The PHP command behind that service is `php artisan exp:ami-listen`. There must be one listener per deployment, with the correct working directory, enabled PBX connection and writable lock path. Restarting it creates a short capture gap; verify its heartbeat and subsequent events. Do not delete a lock file to force a second listener to start.

On 20 November 2026 the targeted supervision repair created and enabled `VitalPBX EXP`, started the EXP AMI listener and database queue worker, and enabled a scheduler timer. Use [the saved systemd configurations and instructions](supervised/README.md) for the installed service names. Verify the current heartbeat and service state before each deployment. Do not restart the existing `gwosevo-*` services as substitutes: those belong to another application.

Check and gracefully reload Apache:

```bash
apache2ctl configtest
systemctl reload apache2
systemctl is-active apache2
```

If EXP is served through PHP-FPM, also gracefully reload its actual FPM service so PHP workers load the release. The host has `php8.3-fpm`; confirm EXP’s Apache handler before using:

```bash
systemctl reload php8.3-fpm
```

Apache and PHP-FPM can serve other sites too, so coordinate these shared-service reloads. Do not reboot the server for an ordinary application update.

## 11. Reopen and verify the application

Once all required preceding steps have succeeded:

```bash
php artisan up
curl --fail --silent --show-error -o /dev/null -w 'Health: %{http_code}\n' http://10.10.20.51:8086/up
curl --fail --silent --show-error -o /dev/null -w 'Login: %{http_code}\n' http://10.10.20.51:8086/login
git status --short --branch
git log -1 --oneline
```

Both HTTP checks should return `200`. Use `10.10.20.51`, not `127.0.0.1`, for this installation: Apache’s EXP virtual host is bound to the server IP, and the loopback URL previously returned a different site’s `404`.

Then check in a browser:

1. Sign in using the intended account. If full seeding reset the administrator password, account for that change.
2. Open Dashboard, Agent Workspace, Call History and User guides; confirm assets load and permissions are correct.
3. Confirm only calls with recorded IVR option `3` appear in the Agent Workspace and can receive case data.
4. Open an existing case and confirm its answers and notes remain intact. Record any test changes only on an identified test call.
5. Place a controlled option-3 call and verify arrival, agent answer, dentist dial/answer or no-answer outcome, transfer, and final hangup. Match the record to PBX evidence.
6. Verify that the AMI heartbeat advances and new events arrive. A cached screen or one old heartbeat is not proof of current capture.
7. Inspect recent EXP application and listener errors. Keep caller data and credentials out of shared troubleshooting output.

If a required check fails, keep or return EXP to maintenance mode, diagnose the problem, and use the recovery steps below. Do not report a successful deployment merely because the homepage loads.

## 12. Confirm scheduled tasks are actually running

```bash
php artisan schedule:list
```

This lists registered tasks; it does not prove that the scheduler is invoked. Confirm the server’s cron or equivalent timer runs it for this application. Production now uses `exp-scheduler.timer` for this invocation. Check it with `systemctl list-timers exp-scheduler.timer --all --no-pager`; do not also install cron. If deliberately replacing the timer with cron, disable the timer first. A cron alternative, installed once for `www-data`, is:

```cron
* * * * * cd /var/www/exp-operations && /usr/bin/php artisan schedule:run >> /var/www/exp-operations/storage/logs/scheduler.log 2>&1
```

Check for an existing entry before adding one. Do not duplicate a cron job or timer. EXP’s schedule includes reconciliation, alert evaluation, callback escalation and telemetry pruning. An AMI listener is a separate continuous process; the scheduler does not replace it.

Record the deployed commit, backup path, migrations, seed choice, restarted EXP services and verification results in the release record.

## Recovery if a deployment fails

### Code or asset rollback

Keep EXP in maintenance mode while recovering. First determine whether the previous code is compatible with the current database schema. If it is, use the protected pre-deployment backup:

```bash
cd /var/www/exp-operations
php artisan down --retry=60

# Set this to the backup directory recorded for this release.
DEPLOY_BACKUP=/var/backups/exp-operations/RELEASE_BACKUP_TIMESTAMP
DEPLOY_PREVIOUS_COMMIT=$(cat "$DEPLOY_BACKUP/previous-commit")

git status --short
git switch --detach "$DEPLOY_PREVIOUS_COMMIT"
COMPOSER_ALLOW_SUPERUSER=1 composer install \
  --no-dev --no-interaction --prefer-dist --optimize-autoloader
tar -xzf "$DEPLOY_BACKUP/assets-and-cache.tar.gz" -C /var/www/exp-operations
php artisan optimize:clear
php artisan optimize
chown -R www-data:www-data storage bootstrap/cache
chmod -R ug+rwX storage bootstrap/cache
```

Investigate any modified checkout before switching; do not discard it. Restart the same EXP processes and reload the same web/PHP services used for the deployment. Reopen and repeat the HTTP and browser checks only after recovery succeeds.

This rollback intentionally leaves Git at the previous commit in detached-HEAD state. Before the next release, merge a fix into `main`, enter maintenance mode, then return to `main` with `git switch main` and deploy the corrected branch. Do not blindly pull the known-bad release again.

### Database or environment recovery

A code rollback does not reverse migrations, seed changes or database writes. Do not automatically run `migrate:rollback` or restore the entire database just to reverse a frontend release.

If database restoration is necessary, stop new writes and EXP background writers, preserve the current database, identify any records written since the backup, and use the tested restore procedure for that backup. Restore the matching environment/key only when needed. Replacing a database can remove newer calls and case updates, so plan how those records will be retained or recovered before restoring.

## Existing deployment script

`docs/exp_deploy.sh` is a diagnostic helper, not the procedure above. Its current implementation continues after failed steps, automatically runs the full seed, does not install locked frontend dependencies, and defaults HTTP checks to the loopback address. Do not use an unqualified `bash docs/exp_deploy.sh` as the production release procedure. Use the explicit steps in this guide until that script is revised and validated to stop on errors and make seeding behavior explicit.

## Framework references

- [Laravel 13 deployment](https://laravel.com/docs/13.x/deployment)
- [Laravel 13 maintenance mode](https://laravel.com/docs/13.x/configuration#maintenance-mode)
- [Laravel 13 queue worker deployments](https://laravel.com/docs/13.x/queues#queue-workers-and-deployment)
