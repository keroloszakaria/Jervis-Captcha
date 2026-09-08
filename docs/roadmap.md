# Acceptance status and remaining work

This document separates implemented capabilities from release assurance. `validation.json` and the exact CI run are authoritative for test execution; a checkbox here is not a substitute for evidence.

## Implemented in this candidate

- [x] Go HTTP engine; explicit private/public API boundaries; trusted host session creation.
- [x] Local SQLite state, revision CAS, persistent counters, expiry cleanup and schema-version guard.
- [x] Embedded PHP engine that runs in-process in Laravel with no separate Jervis service, daemon, port or container.
- [x] Laravel package-owned routes, server-session binding, atomic cache locks, one-use proof consumption, middleware, Blade component, install/doctor commands and fail-closed storage behavior.
- [x] Laravel 11/12/13 Composer compatibility constraints with a dedicated Testbench CI matrix for the matching generations.
- [x] Text, slider, free drag, rotation, ordered click, English audio, math, logic and PoW generators/verifiers in the embedded PHP engine.
- [x] Shared local English digit-audio source payload between Go and PHP; the embedded build verifies the copied audio asset byte-for-byte and uses server-generated WAV instead of browser TTS.
- [x] Server-owned `standard` / `accessible` session variants. Managed clients cannot force a concrete type, binding, policy or `isAntiAI`; the default accessible pool uses local audio.
- [x] Backend-owned antiAI profile with bounded PoW and experimental raster noise; no AI-proof claim.
- [x] Arabic/English web UI, RTL, themes, keyboard controls, cancellation, expiry, recovery, native form association and an accessible-challenge switch in managed Laravel mode.
- [x] `@jervis/captcha` framework-neutral browser package with same-origin Laravel session bootstrap; SPA consumers need only an import plus `<jervis-captcha action="...">` for the default route prefix.
- [x] Generic native API, server clients for Node/PHP/Python/.NET, framework integration recipes and compiled cross-runtime consumer fixtures.
- [x] Embedded endpoint budgets independent of action names, plus per-session attempts/refresh limits and issuance limits.
- [x] Native build/packaging workflow, embedded Composer/npm offline packaging workflow, checksum manifest, dependency inventory and local notices.
- [x] Tests defined for replay, concurrent state transitions, wrong contexts, expiry, refresh/attempt budgets, request budgets, policy changes, origin checks, malformed inputs, type-forcing rejection, accessible audio fallback, offline browser requests and real widget-to-engine integration across Chromium, Firefox and WebKit.
- [x] Native Windows/Linux/macOS acceptance jobs and a loopback-only Linux network namespace gate. Execution results are recorded by CI; configuring a job alone is not a pass.

## Must be verified before a customer production rollout

- [ ] Obtain a successful **full** CI run for the exact candidate source commit. Current GitHub-hosted jobs have been failing before any step starts (`runner_id=0`), so they are not evidence of test failure or success.
- [ ] Obtain a successful **Embedded PHP package** run for the exact candidate: TypeScript typecheck/unit tests, canonical Vite artifact, Laravel 11/12/13 Testbench tests, Composer audit, offline archive generation and checksum verification.
- [ ] Run a real consuming Laravel application fixture against the packaged ZIP/TGZ, including Blade and a same-origin SPA path, not only Testbench/package-level routes.
- [ ] Test supported persistent cache stores individually (at minimum database/file for single-node deployments and Redis for shared/multi-node deployments), including concurrent proof consumption and lock failure behavior.
- [ ] Perform load/resource-exhaustion tests for PHP-FPM workers, GD image generation, audio generation, PoW, session creation and invalid-token traffic; tune default budgets from measured results.
- [ ] Customer-native Windows/macOS/Linux runtime acceptance and first-boot packet capture with Internet blocked for native deployments.
- [ ] Customer reverse-proxy/TLS/ACL, OS/data permissions, secret provisioning and rollback recovery checks.
- [ ] Independent security review, solver/adversarial benchmark and human usability/accessibility assessment.
- [ ] Owner's package licensing choice, release approval and an authenticated signing/update process. Checksums alone are not signatures.

## Explicitly not implemented

- Biometric/face/voice identity or liveness, WebAuthn/passkey enrollment and verification.
- ML/adaptive risk scoring, behavioral fingerprinting, global IP reputation or bot-farm detection.
- Proven anti-AI fonts, model-specific resistance guarantees, motion-font challenges.
- Arabic-spoken audio assets and a fully audited accessibility route for every disability/user group. The current accessible variant is an English digit-audio alternative, not universal accessibility coverage.
- Published Vue/React/Angular wrapper packages, Angular ControlValueAccessor and native mobile UI. The Web Component itself remains the supported cross-framework surface.
- Dedicated shared-state adapters beyond Laravel's lock-capable cache abstraction, signed installers/auto-updates or offline source toolchain kits.

## Next implementation order

1. Close package acceptance with a real Laravel consumer fixture and cache-store concurrency tests.
2. Restore/obtain successful GitHub-hosted CI evidence for the exact candidate and generate verified offline artifacts.
3. Run security/load/accessibility benchmarks and tune defaults from measured data.
4. Publish the Composer/npm packages only after licensing, signing and release approval are decided.
5. Add thin framework wrappers only where they materially improve forms/SSR ergonomics; do not duplicate CAPTCHA logic outside the core Web Component.
6. Add optional WebAuthn later as a separately reviewed account step-up mechanism rather than pretending it is anonymous human detection. Keep camera-based liveness outside the core CAPTCHA package.