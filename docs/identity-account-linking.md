# Account Linking & Provisioning — CajeerEngine 1.5.2

The active local user is the only account eligible for self-linking; cookie/CSRF and local password/2FA are required. The browser never provides a target UUID or the OIDC subject. Identity is established by the IdP's cryptographically verified `sub`, issuer and nonce. Callback consumes a five-minute browser-bound transaction and refuses revoked local sessions. Users cannot take over other accounts by claiming a matching email.

Enable optional new-user registration with `SKIF4ER_ID_AUTO_PROVISION=true`, after reviewing that the local `viewer` role contains only baseline read scopes. Registration requires verified email. Newly provisioned accounts receive an undisclosed random password hash; unlinking is blocked until a verified local alternative is configured by a separate procedure. OIDC-linked accounts retain their local RBAC permissions.

Routes: `GET /api/v1/identity/account`, `POST /api/v1/identity/link/start`, `POST /api/v1/identity/unlink`. Browser POSTs require a valid `ce_session` HttpOnly cookie and `X-CSRF-Token`, with a recent password + optional TOTP. Audit actions: `identity.oidc_self_link`, `identity.oidc_self_unlink`, `identity.oidc_provisioned`. No JWT, token or OIDC subject is serialized to account status.

Apply SQL migration 0027 for all supported DB engines. Check account linking with a registered confidential SkiF4er ID client and a disposable test account on staging before enabling in production. Do not enable `SKIF4ER_ID_AUTO_PROVISION` before confirming user-registration policy.
