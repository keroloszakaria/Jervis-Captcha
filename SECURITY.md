# Security boundaries

This alpha is defense-in-depth for authorized application owners. It is not an independently audited anti-bot platform, proof of a unique human, a biometric identity system or a guarantee that AI cannot solve a challenge.

## Trust and integration

The application backend and local CAPTCHA engine are trusted. Browsers, widget events, requested form values, pointer movement, timing claims and network callers are not. An administrator who controls the operating system/database/configuration can bypass local controls; protecting a desktop app from its own administrator is out of scope.

Expose only `/v1/challenges`, `/v1/answers` and static widget assets through the public reverse proxy. Keep `/v1/sessions` and `/v1/siteverify` private. They require the per-site secret in `Authorization: Bearer ...` and reject browser Origin headers. Origins are a defense-in-depth browser control, not an authentication mechanism; non-browser clients can forge them.

The host backend obtains a stable, unpredictable binding from its own application session. Never accept an attacker-supplied binding from the form. Verify the expected action and binding at the protected endpoint, retain normal CSRF/session/authentication checks, and reject every non-success service response. Apply rate limits at the business endpoint and the host session-creation endpoint as well.

Proof consumption is **at most once**, not an exactly-once business transaction across two services. A response can be lost after successful consumption; do not automatically retry the proof or grant access on timeout. Obtain a fresh verification session, and use the application's separate idempotency/transaction model for business operations.

## Protections and limitations

- CSPRNG session/challenge/proof tokens; session and proof credentials are hashed at rest. Application bindings use a per-site HMAC. Expected challenge answers remain only in the protected local database and are cleared after a successful solution.
- SQLite compare-and-swap protects concurrent solving and consumption, including different database connections. State and limits survive restart. Fixed-window counters can admit a boundary burst; they are not a global distributed traffic-management solution.
- Attempt budgets do not reset on refresh. Refresh invalidates the previous challenge. TTLs and minimum solve time use the engine clock. Clock rollback before session creation rejects the session; use a trusted system clock and do not assume tamper-resistant time.
- Policy changes revoke sessions/proofs whose saved policy no longer matches. Removing an allowed origin blocks new public operations. Rotating a site secret invalidates proof binding; expect outstanding sessions to fail.
- `isAntiAI` requires local PoW and experimental text rendering. It prohibits math/logic in that policy. It provides **no measured AI resistance claim**. Visual puzzles have limited answer entropy; proof-of-work only adds cost. Attackers can use solvers, farms, real browsers or replay newly relayed tasks.
- PoW runs in a terminable worker with a device budget. Slow/unsupported devices fail explicitly; the application must offer an approved alternative without silently creating a downgrade bypass.
- Audio uses bundled English digit speech. Audio is not guaranteed accessible to everyone or resistant to speech recognition. Keyboard support is not a WCAG conformance claim.
- No face capture, voiceprints, behavioral fingerprinting or biometric databases. WebAuthn is not implemented in this release.

## Runtime and operations

Run the engine under a dedicated account. Bind to loopback behind an internal TLS reverse proxy. For a remote engine, restrict private routes with network ACLs/TLS and per-site keys. Protect the configuration, database and WAL files with OS permissions/Windows ACLs. Back up the configuration and consistent database state together. Restoring an old database snapshot can restore unexpired proof state: invalidate old sessions on disaster recovery (rotate site secrets) rather than allowing replay after rollback.

Deny outbound network traffic at the customer firewall; application configuration alone cannot guarantee absence of egress in all environments. The engine makes no outbound network requests. The widget uses only the configured endpoint, local assets and inline data/blob media. Browser/OS traffic outside the product is not controlled by the package.

Do not run `tests/fixture` on a customer system. Demo mode is development-only, loopback-only and must not be reverse-proxied into production. Review dependencies, update signed/trusted distribution channels and verify checksums. A checksum detects corruption; it does **not** authenticate an attacker-replaced update.

## Reporting

Report suspected vulnerabilities privately to the repository owner. Include affected source commit, reproduction steps and impact without live customer secrets. Do not put exploitable customer details in a public issue. No external security review has yet been completed.
