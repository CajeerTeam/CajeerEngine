# Update Execution Service — CajeerEngine 2.0.3

The dedicated **one-shot** operator processes exactly **one** authenticated and explicitly confirmed `apply` request per systemd timer invocation. This is a functional installation consumer calling the existing `UpdateManager::runRequest()` (including verified package re-check, backup, maintenance, migrations and recovery). It **does not** download new releases or automatically approve updates. CLI staging remains required until the Admin Update Center integration. `rollback` and `backup` requests remain manually operated for now.

## Deployment (single Linux host)

1. Install the verified, signed CajeerEngine 2.0.3 distribution. The changed-files archive is not a signed release and must not be passed to `update:apply`.
2. Create a dedicated **non-root** Unix account `cajeer-updater` (not the Nginx/PHP-FPM account) and a shared `cajeer-update-requests` group containing only the updater and the PHP-FPM account for narrowly scoped request-file access. Grant this account write access to the installed managed code, the update transaction/backup directories, the database recovery operations and the existing runtime paths it must mutate. Use OS ACLs so the web account can create `storage/app/update-requests/request-*.json` but cannot write application code; allow the updater to read and atomically replace request files and write `storage/app/updates`. Ensure directory traversal permissions, group/ACL persistence on atomic rename, and no write access for untrusted accounts. Never grant the web account code replacement rights. Set `storage/app/update-requests` group to `cajeer-update-requests`, setgid directory mode `2770`, and default ACLs so new request files retain group readability after each atomic rename. The updater must be able to create/rename entries there; web workers must never have write rights to the managed application code. Give the updater a traversable and writable `storage/app/updates`, and permit web read-only access to the `operator-status.json` file. Check permissions on the full paths with `namei -l`/`getfacl` before enabling the timer. Installation is **not** safe merely by copying the sample unit without provisioning permissions.
3. Configure externally stored public Ed25519 key and backup credentials (`UPDATE_TRUSTED_PUBLIC_KEY_FILE`, `BACKUP_KEY_FILE`) for the updater. Its process must receive the same database connection secrets as the regular installer. Set in `.env`:

   ```dotenv
   UPDATE_WEB_UPGRADE_ENABLED=true
   UPDATE_OPERATOR_ENABLED=true
   ```

4. Adapt both files in `examples/systemd/cajeerengine-update-operator.{service,timer}` to your actual installation path and Unix account. Install them in `/etc/systemd/system` as root, then:

   ```bash
   sudo systemctl daemon-reload
   sudo systemctl enable --now cajeerengine-update-operator.timer
   sudo systemctl list-timers cajeerengine-update-operator.timer
   sudo journalctl -u cajeerengine-update-operator.service -n 100 --no-pager
   ```

   `TimeoutStartSec=0` permits a long-running installation; apply your operational limits and monitor the timer. Never run the service as root or PHP-FPM. The systemd service is one-shot so it terminates after replacing application code; the next cycle starts a fresh PHP interpreter.

## Approve and execute a real installation

1. Download and stage a **signed production dist** from GitHub using the existing operator commands:

   ```bash
   php bin/cajeer update:download
   php bin/cajeer update:stage --package=/absolute/path/to/verified-dist.zip
   ```

   Stage creation returns a package ID and SHA-256. The admin API route `POST /api/v1/updates/apply` requires an authenticated authorized admin, `UPDATE_WEB_UPGRADE_ENABLED=true`, JSON containing the exact `package` ID, `sha256`, and `"confirm": true`. The server writes a durable, time-stamped, explicitly confirmed request (`state=pending`, `confirmed_at`). Existing pre-2.0.3 request files without the server-side confirmation timestamp require manual handling and are not consumed automatically. The timer executes that request automatically; **no** manual `update:run` invocation is needed.

2. Observe request state using `php bin/cajeer update:requests`, `php bin/cajeer update:status`, `storage/app/update-requests/request-<id>.json`, `storage/app/updates/operator-status.json`, `storage/app/updates/active.json` and `journalctl`. A completed request contains `state=completed` and the update report. An exception records `failed`. A SIGKILL/power loss leaves `running`, preventing automatic retry of an uncertain operation.

## Recovery policy

- On a `running` request or `active.json`, the operator reports `recovery_required` and **does not** consume later requests. Inspect transaction and database restore journals before deciding on manual recovery. Do **not** reset `running` to `pending` or delete journal/lease files.
- The worker consumes only `apply` requests. Browser-generated rollback/backup requests are not unattended in this release.
- Disable either `UPDATE_OPERATOR_ENABLED` or `UPDATE_WEB_UPGRADE_ENABLED`, or stop the timer, before incident recovery. Both flags must be enabled for unattended consumption. Audit all staged files and trust keys, then verify health and version before re-enabling.
- No automatic installation of newly discovered versions occurs without an authenticated administrator approving the exact staged digest. Multi-node deployments and container orchestration are out of scope. The root-managed immutable deployment profile in `deploy/production.py` intentionally disables HTTP updating and must not be assumed compatible with this account-based systemd operator without a separate reviewed integration.

## CI smoke tests

`php tools/update-execution-service-regression.php` covers queue ordering, disabled/idle, malformed requests, interrupted requests, single consumption, concurrency and the real `runRequest()` rejection path. Live signed dist + PostgreSQL recovery still require a dedicated staging deployment.
