# EXP production process supervision

These are the systemd configurations for `/var/www/exp-operations` on `10.10.20.51`. Systemd supervises the PHP processes and starts them after reboot; installing the separate Supervisor package is not necessary.

| File | Purpose |
|---|---|
| `exp-ami-listener.service` | Persistent read-only VitalPBX AMI capture, with restart on process failure |
| `exp-scheduler.service` | Runs one Laravel `schedule:run` invocation as `www-data` |
| `exp-scheduler.timer` | Invokes the scheduler every minute |
| `exp-queue-worker.service` | Processes EXP’s configured standard queue; restarts after errors or its one-hour worker recycle |
| `enable-pbx.php` | Creates the configured PBX connection from existing application credentials if missing, enables it, and enables an existing disabled AMI feature flag |
| `install.sh` | Validates and installs the units, backs up existing unit files, and starts supervision |

## Production repair verified on 20 November 2026

The saved connection `VitalPBX EXP` was created using the existing application configuration and enabled. The listener connected to `10.10.20.50:5038` and began receiving AMI events. The listener, queue worker and scheduler timer were enabled for automatic startup.

Configuration copies are in this folder. Installed production units are in `/etc/systemd/system/`; installation snapshots are in `/var/backups/exp-operations/supervision-*`. The production Git checkout was not pulled or seeded during this repair, and no administrator password was changed.

This verifies connectivity and event receipt. A controlled call selecting option `3`, followed by a dentist attempt and hangup, is still required to verify the complete IVR/transfer journey. Do not create synthetic production call records to stand in for that test.

## Install or reproduce the setup

1. Deliver this folder to the production checkout through the normal reviewed release process. The production directory must be readable by `www-data`.
2. Ensure production `.env` or cached application configuration contains the correct `EXP_PBX_NAME`, `EXP_PBX_HOST`, `EXP_PBX_AMI_PORT`, `EXP_PBX_AMI_USERNAME` and `EXP_PBX_AMI_PASSWORD`. Credentials are not stored in these service files.
3. Check for an existing manually started EXP listener or another EXP scheduler. Stop or reconcile duplicate EXP processes before installation. Do not stop the server’s unrelated `gwosevo-*` services.
4. Run as root on production:

   ```bash
   cd /var/www/exp-operations
   bash docs/supervised/install.sh
   ```

5. Confirm the connection and advancing heartbeat:

   ```bash
   php artisan pbx:status
   systemctl status exp-ami-listener.service --no-pager
   systemctl status exp-queue-worker.service --no-pager
   systemctl list-timers exp-scheduler.timer --all --no-pager
   systemctl show exp-scheduler.service -p Result -p ExecMainStatus
   ```

The installer uses the application’s existing `config:exp-pbx:set` command internally without putting credentials into process arguments. It preserves an already saved connection’s host and credentials and enables that connection. The helper does not run migrations, full database seeding or account resets.

The queue worker uses the configured connection and default queue. Production was configured with the `database` driver and `retry_after=90` seconds; the worker timeout is 60 seconds and systemd allows 90 seconds to stop. Review those values together if the queue configuration changes. Do not run this worker alongside another worker manager consuming the same intended EXP workload without deliberately planning that concurrency.

The scheduler service is a one-shot process. It normally shows `inactive` between timer runs; the timer should remain `active`, and completed runs should have `Result=success` and `ExecMainStatus=0`. Do not add a cron `schedule:run` entry while using this timer. The installer rejects a matching existing cron entry; also check for other custom timers or scheduler processes.

## Restart after a deployment

```bash
systemctl restart exp-ami-listener.service
systemctl restart exp-queue-worker.service
systemctl restart exp-scheduler.timer
```

This restarts EXP’s processes only. A listener restart creates a short capture gap, so use an appropriate maintenance window and verify events afterwards. Changes to the unit files additionally require `systemctl daemon-reload` before restarting services.

## Check logs and event capture

```bash
journalctl -u exp-ami-listener.service --since '10 minutes ago' --no-pager
journalctl -u exp-queue-worker.service --since '10 minutes ago' --no-pager
journalctl -u exp-scheduler.service --since '10 minutes ago' --no-pager
```

Keep caller information and credentials out of shared troubleshooting output. A running systemd process alone does not prove that AMI authentication succeeded: check `pbx:status`, heartbeat renewal, event receipt and connection errors.

For a controlled real call, confirm:

1. The caller’s option `3` is recorded, and the call appears in Agent Workspace.
2. Arrival and agent-answer timestamps appear when received from the PBX.
3. Each dentist attempt retains dial/ringing/answer, transfer and no-answer/busy/failure outcomes separately.
4. The final call end is recorded after the phone-call legs end, independently of closing the case form.

Missing historical events are not replayed by starting the listener. Use retained AMI replay or read-only PBX exports when available. Missing evidence must remain marked as missing.

## Change AMI credentials safely

A saved PBX connection takes precedence over the corresponding environment credentials. Editing `.env` alone does not replace a password already saved in the connection record.

Use the administration interface or the application command with its interactive password prompt:

```bash
cd /var/www/exp-operations
php artisan config:exp-pbx:set 'VitalPBX EXP' '10.10.20.50' 5038 'YOUR_READ_ONLY_AMI_USERNAME'
systemctl restart exp-ami-listener.service
php artisan pbx:status
```

Enter the AMI password at the prompt; do not append it to the shell command. Use the actual connection name and read-only account for the deployment.

## Stop or disable the services

For a temporary listener stop:

```bash
systemctl stop exp-ami-listener.service
```

For a planned permanent removal of EXP supervision:

```bash
systemctl disable --now exp-ami-listener.service exp-queue-worker.service exp-scheduler.timer
systemctl stop exp-scheduler.service
```

Stopping capture interrupts the call evidence stream. These commands preserve existing records and the saved PBX connection. Restore backed-up unit files only when needed, run `systemctl daemon-reload`, and re-enable the intended EXP services afterwards.
