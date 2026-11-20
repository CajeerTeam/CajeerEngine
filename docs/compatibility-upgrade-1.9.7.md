# Compatibility & Upgrade — CajeerEngine 1.9.7

## Scope and deployment contract

This release changes the real extension compatibility checks, Core Updater preflight and TypeScript SDK endpoint validation. It does not change the REST route catalogue or add database migrations. The Engine API remains `1.1`. CMS updates continue to require an operator, an authenticated signed `dist` ZIP, maintenance mode, a verified backup and post-update checks. This patch adds a preflight check **before** maintenance and destructive file replacement.

## Engine API and extension dependencies

Extension manifests use `cajeer.extension.json` with an `engine` version constraint and optional `requires`/`conflicts` maps. Both Engine API and dependency versions now use one strict parser supporting `*`, `^1.1`, `~1.1`, `>=1.1 <2.0`, and comma-separated conjunctive ranges. Invalid or unsupported expressions fail closed; `||` and arbitrary wildcard combinations are intentionally not supported. The caret semantics for `0.x` follow the first nonzero version component, so `^0.0.3` does not permit `0.0.4`. Existing example manifests using `^1.1` are still valid.

Before a production CMS update, the updater extracts the release metadata **from the signed and checksum-verified dist ZIP**. For each enabled extension it checks that the installed manifest still matches the extension registry and that its `engine` range accepts the target `engine_api_version`. The update is rejected before backups or file changes if an enabled extension is incompatible or missing. Disabled extensions do not block an update. Extension package changes still require their normal signature and dependency verification.

If an upgrade is blocked, review the named extension and install a compatible signed update of that extension, or explicitly disable it if your site does not require its functionality. Do not edit the extension registry to bypass the check. The usual restoration procedure is documented in [Deployment & Recovery](deployment-recovery-1.8.7.md).

## Update catalog channels

An offline or custom update catalog must not claim that `1.9.8-rc.1` is stable. The resolver checks SemVer prerelease segments against both `channel` and `stability`, if supplied, and requires a `dist` artifact record. The `dev` channel can discover entries from other channels but entries must still be internally consistent. GitHub Release normalization continues to require published, complete artifacts. No unsigned payload is installed from the resolver; applying a package still requires the trusted release key.

## PHP and TypeScript SDKs

The 268 existing OpenAPI operations and their generated PHP and TypeScript operation registries are preserved. The TypeScript client now rejects remote HTTP, userinfo, URL fragments and embedded query parameters in `baseUrl`, just like the PHP SDK. Plain HTTP is permitted only for local development on `localhost`, `127.0.0.1`, and `::1`. Bearer tokens with newlines are rejected. The validated endpoint is immutable after construction, so mutation of a caller-owned options object cannot reroute credentials to another origin. The emitted ESM SDK now imports `./operations.js`, so native Node ESM can load the built package without a bundler. Generated calls continue to encode path parameters and reject redirects.

Clients that previously passed insecure `http://` production endpoints must configure HTTPS. This is an intentional security compatibility change; check proxy/TLS termination and do not put credentials in URLs.

## Verification and release gates

Run from the project root:

```bash
php tools/compatibility-upgrade-regression.php
php tools/extension-regression.php
php tools/github-release-regression.php
php tools/generate-api-sdk.php --check
node tools/sdk-client-compatibility-regression.mjs
php tools/ci-check.php
```

The TypeScript regression requires `tsc` (available after installing the TypeScript SDK development dependencies). CI uses the same executable tests, plus full PHPUnit, static analysis, live database compatibility, production dist build and rollback checks. Before production rollout, perform a signed dist update against a staging copy with **actual installed enabled extensions** and validate that both a compatible and deliberately incompatible Engine API transition produce the expected results. A source ZIP or this changed-only patch is not a production dist certification.

Rollback remains a separate signed-operation and backup/recovery workflow; do not attempt a downgrade by changing release metadata or unpacking unrelated source files over a production installation.
