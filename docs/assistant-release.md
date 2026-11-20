# Release on request (ChatGPT / GitHub)

To start an official versioned release without a local Git command, add or update
`.github/release-request.json` on the **main** branch using a reviewed pull
request (or an authorized repository-file edit):

```json
{
  "version": "2.0.0",
  "publish": true,
  "request_id": "2.0.0-initial"
}
```

The `Release verification and publication` workflow is triggered by a push
changing this exact file on `main`. It verifies that the requested stable
version agrees with `VERSION` and `release.json`. The complete release job
then runs its existing test, database, production-host, dependency-audit,
distribution and certification checks. A failed check prevents publication.

After those checks succeed, the protected `release` environment controls
access to `RELEASE_SIGNING_KEY`. The publish job verifies certification,
signs the artifacts, creates an **immutable** annotated `vX.Y.Z` tag pointing
to the exact tested commit, and publishes the signed GitHub Release with
asset digests verified. An existing tag or release is never overwritten.

This workflow does not bypass environment approval, signing, certification,
or any release gate. If a run fails before creating a tag, fix the cause and
change `request_id` in a new commit to run it again. A failure after tag
creation requires checking the partial draft and recovering deliberately; do
not force-update the tag or replace signed release bytes.

Direct pushes of reviewed `vX.Y.Z` tags remain supported. A manual
`workflow_dispatch` runs verification only, as before; publication requires
a tag push or a committed release request.

For subsequent releases, update the synchronized project version metadata
first. Then change the request file to the matching version and a new
`request_id`.
