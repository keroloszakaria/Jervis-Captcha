# Air-gapped deployment

## Runtime bundle

Each native package contains the executable, documentation, dependency inventory, and third-party notices. The executable contains the browser widget, its worker, generated images/audio logic and English digit samples. `init` generates customer-local secrets; there is no common production key.

No outbound API, activation server, CDN, analytics endpoint, automatic updater or runtime package installer is configured. Application endpoints may be loopback or internal network addresses; **local does not mean browser-only**. The trusted verifier must remain server-side.

## Acceptance in the customer environment

On a clean machine, block outbound Internet traffic and clear browser caches before first run. Create configuration, start the service, exercise every enabled challenge, solve and consume a proof, and confirm a replay fails. Monitor both backend and browser traffic. Browser/OS background traffic is separate from product requests; scope the capture accurately.

Repeat after restart and after a binary update. Remove a required asset/configuration and confirm a visible failure, not a download or fail-open success. Confirm exact origin allowlists, private endpoint ACLs, trusted TLS, backup recovery and disk access permissions. Do not equate a local browser request-routing test with a complete packet-level audit on Windows/macOS.

## Building versus running

A connected build machine is allowed to resolve pinned dependencies. CI records Go module checksums and npm lockfiles and compiles the runtime assets. The deployment machine does not need those tools.

An offline **source** rebuild requires the toolchains, dependency caches/vendor directory and generated assets to be transferred in advance. A repository checkout alone is not sufficient. Do not run `npm install`, `go mod download`, eSpeak installation or Playwright browser installation on an isolated customer machine expecting Internet access.

## Updates

Transfer an approved archive through the customer's update process. Verify its SHA-256 against an independently trusted manifest. Checksums detect corruption, not malicious replacement of both archive and manifest; distribution signing/key management remains a release-hardening task. No built-in auto-update client runs in the customer environment.

Read the database schema version before deploying an older binary. This version rejects a newer database schema instead of attempting a destructive downgrade. Preserve a consistent backup and validate a recovery plan. Secret rotation is required when rolling back persisted verification state.

## Operating-system coverage

CI cross-compiles Linux amd64, Windows amd64 and macOS arm64 archives. Compilation is not proof of successful installation on each OS. Native acceptance, service-manager integration, Windows ACL validation and macOS signing/notarization remain customer/release checks until corresponding native CI jobs are added.
