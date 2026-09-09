# Jervis Captcha engineering rules

- Treat offline/air-gapped runtime operation as an invariant. No CDN, telemetry, auto-download, remote activation, runtime package installer or cloud CAPTCHA dependency.
- The backend engine is Go; the browser widget is TypeScript/Lit. The host backend integrates by HTTP and stays in its existing language.
- Never put expected answers, seeds capable of reconstructing them, site secrets, application session identifiers or answer hashes into browser responses. A public PoW seed is intentional and separate.
- Policies and challenge selection are server-owned. Never let a public request disable isAntiAI, change TTLs, switch to an unapproved type or reset attempt budgets.
- Proof consumption must be atomic and restart-safe. Signed/opaque tokens alone are not sufficient. Do not add retry logic around consumption.
- Preserve origin validation, strict bounded JSON, action/session binding, rate limits and fail-closed errors.
- The E2E answer oracle exists only in tests/fixture. NEVER add it, fixed answers, test keys or a bypass flag to cmd/jervis-captcha or internal/httpapi.
- Do not claim AI-proof, universal human detection, WCAG compliance, biometric support or compatibility testing that has not been demonstrated.
- Default UI must remain Arabic/English, keyboard-operable, touch-capable and compatible with reduced motion.
- Validate with npm run validate:web, go test -mod=readonly -race ./..., go vet -mod=readonly ./..., npm run test:e2e, dotnet build sdk/dotnet and PHP/Python checks. The repository root may also contain Composer's PHP `vendor/`; Go commands must not interpret that directory as Go vendoring. SQLite must be tested, not replaced by an in-memory mock for release acceptance.
- Never commit jervis.json, customer data, live secrets or node_modules. Do not publish packages or deploy customer services without explicit authorization.
