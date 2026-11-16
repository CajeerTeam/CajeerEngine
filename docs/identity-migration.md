# Users & Identity Migration (1.4.5)

Apply `php bin/cajeer migrate` (0020) before executing a DLE or WordPress project. Keep the existing application keys and backups. Recompute and approve old, unexecuted plans: identity fingerprints now include source profiles and target role permissions. Completed projects remain immutable; their receipts are not retroactively changed.

In `/admin/#/migration`, configure the source, run Discovery, select content mapping and run Dry Run to discover source IDs. For each source identity, explicitly select `create`, `skip`, or an existing active target UUID. Email matches never automatically merge accounts. Optional `user_roles` is a map of source ID to `viewer` or `editor` for `create` actions, e.g. `{"7":"editor"}`. The default is viewer. The actual target permissions must be a subset of the built-in role's safe permissions. Source groups/capabilities are informational, never automatically grant privileges. Existing linked users are never modified. DLE group and banned metadata, WordPress database capabilities, REST roles when available, and WXR author profiles are read without executing source code or copying password hashes. REST user edit context requires sufficient source privileges; WXR only includes exported authors. Missing/duplicate email must be resolved by explicit link or skip.

Approve the plan and use the existing `migration:dle-import` or `migration:wordpress-import` command. New accounts, role associations, source-to-target identity rows, content author links, SQL audit events and import receipt commit together. New accounts have an unknowable random password hash, no sessions, and disabled status. DLE banned and flagged WordPress DB accounts are marked `blocked`, not eligible for the migration activation command. The SQL receipt records user IDs, decisions, roles and activation state at import time (not current account status). Existing import limits (500 records/users and 16 MiB inventory) remain. The target must be database-backed.

## Operator activation

After verifying ownership of the original email out of band and obtaining a strong user-chosen password through an approved secure channel, pass exactly one password line through protected stdin:

```sh
php bin/cajeer migration:activate-user USER_UUID --yes < /run/secrets/new-user-password
```

Use an ephemeral secret file with mode 0600 or a secure secret-manager pipe; do not put passwords in shell history, command-line arguments, logs or shared files. Remove the temporary file immediately. Interactive terminal input is refused to avoid echoing passwords. The command takes the exclusive operation lock, validates pending state, disabled account and unchanged safe roles, hashes the password with the configured PasswordHasher, and atomically activates the user and records the audit event. Repeated activation and blocked accounts are rejected. The normal login flow then applies. No legacy hashes, source sessions, API tokens, MFA secrets or recovery codes are imported. This version does not send invitation emails or expose a public self-service activation URL. For a blocked account or a changed role, use the normal administrative review process; do not bypass the source restriction automatically.

The standard database backup includes the new identity registry. A migration project is one completed batch; creating another project is not a global identity deduplication mechanism. Existing email collisions are still checked. User identity records are retained as audit receipts, even if the account is later removed.
