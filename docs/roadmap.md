# Acceptance status and remaining work

This document separates implemented capabilities from release assurance. `validation.json`, successful CI runs and explicitly recorded local acceptance are authoritative for test execution; a checkbox here is not a substitute for evidence.

## Implemented in this candidate

- [x] Go HTTP engine; explicit private/public API boundaries; trusted host session creation.
- [x] Local SQLite state, revision CAS, persistent counters, expiry cleanup and schema-version guard.
- [x] Embedded PHP engine that runs in-process in Laravel with no separate Jervis service, daemon, port or container.
- [x] Laravel package-owned routes, trusted session binding, atomic cache locks, one-use proof consumption, middleware, Blade component, install/doctor commands and fail-closed storage behavior.
- [x] Laravel 11/12/13 Composer compatibility constraints with a dedicated Testbench/fresh-app matrix for the matching generations.
- [x] Text, slider, free drag, rotation, ordered click, English audio, math, logic and PoW generators/verifiers in the embedded PHP engine.
- [x] Shared local English digit-audio source payload between Go and PHP; the embedded build uses server-generated WAV instead of browser TTS.
- [x] Server-owned `standard` / `accessible` session variants. Managed clients cannot force a concrete type, binding, policy or `isAntiAI`; the default accessible pool uses local audio.
- [x] Backend-owned antiAI profile with bounded PoW and experimental raster noise; no AI-proof claim.
- [x] Arabic/English web UI, RTL, themes, keyboard controls, cancellation, expiry, recovery, native form association and an accessible-challenge switch in managed Laravel mode.
- [x] `@jervis/captcha` framework-neutral browser package with same-origin Laravel session bootstrap; SPA consumers need only an import plus `<jervis-captcha action="...">` for the default route prefix.
- [x] Managed session metadata synchronizes the server-owned proof `fieldName` into the SPA widget automatically.
- [x] Generic native API, server clients for Node/PHP/Python/.NET and framework integration recipes.
- [x] Embedded session/challenge/answer request budgets independent of action names, plus per-session attempts/refresh limits and issuance limits.
- [x] Package-owned 32 KiB verification request guard and bounded answer schema.
- [x] Strict policy/config parsing, explicit allowlist mode, safe route-prefix validation and mandatory Laravel `web` middleware on managed verification routes.
- [x] Native build/packaging workflow plus minimal Composer/npm offline packaging with checksum manifest and local third-party notices.
- [x] Fresh Laravel consumer fixture, cross-process File/Database/Redis proof-race fixtures and network-disabled local-Composer-ZIP install gates are defined in the focused workflow.
- [x] Tests defined for replay, concurrent state transitions, wrong contexts, expiry, refresh/attempt budgets, request budgets, policy changes, origin checks, malformed inputs, type forcing, accessible audio fallback, offline browser requests and real widget-to-engine integration across Chromium, Firefox and WebKit.
- [x] Native Windows/Linux/macOS acceptance jobs and a loopback-only Linux network namespace gate. Execution results are recorded by CI; configuring a job alone is not a pass.

## Development validation already observed

A Windows development machine has executed the embedded candidate with PHP 8.4 / Laravel 13 dependencies. The observed local checks included TypeScript compilation, 19/19 Vitest tests, canonical Vite packaging, Composer manifest validation, Composer dependency audit with no advisories reported at that run, 43/43 PHP/Laravel tests with 545 assertions, deterministic embedded ZIP/TGZ generation and a successful npm dry-run package containing only the intended runtime files.

This local evidence is useful for development but **does not replace** the Laravel 11/12 matrix, hosted CI, real browser/native matrix, concurrency stores or production/customer acceptance below.

## Must be verified before a customer production rollout

- [ ] Obtain a successful **full** CI run for the exact candidate source commit. Current GitHub-hosted jobs have repeatedly failed before checkout or any test step starts (`runner_id=0`, `steps=[]`), so those runs are not evidence of implementation failure or success.
- [ ] Obtain a successful **Embedded PHP package** run for the exact candidate: canonical browser build, Laravel 11/12/13 package tests, fresh-app installs, Composer audit, File/Database/Redis cross-process proof races, offline archive generation and network-disabled local-ZIP installation.
- [ ] Run the real consuming Laravel fixture against both Blade and same-origin SPA submission paths using the final packaged artifacts.
- [ ] Perform load/resource-exhaustion tests for PHP-FPM workers, GD image generation, audio generation, PoW, session creation and invalid-token traffic; tune defaults from measured results.
- [ ] Customer-native Windows/macOS/Linux runtime acceptance and first-boot packet capture with Internet blocked for native deployments.
- [ ] Customer reverse-proxy/TLS/ACL, OS/data permissions, secret provisioning and rollback recovery checks.
- [ ] Independent security review, solver/adversarial benchmark and human usability/accessibility assessment.
- [ ] Owner's package licensing choice, release approval and an authenticated signing/update process. Checksums alone are not signatures.

## Explicitly not implemented

- Biometric/face/voice identity or liveness, WebAuthn/passkey enrollment and verification.
- ML/adaptive risk scoring, behavioral fingerprinting, global IP reputation or bot-farm detection.
- Proven anti-AI fonts, model-specific resistance guarantees or motion-font challenges.
- Arabic-spoken audio assets and a fully audited accessibility route for every disability/user group. The current accessible variant is an English digit-audio alternative, not universal accessibility coverage.
- Published Vue/React/Angular wrapper packages, Angular ControlValueAccessor and native mobile UI. The Web Component itself remains the supported cross-framework surface.
- Signed installers/auto-updates or offline source toolchain kits.

## Next implementation order

1. Land the locally validated embedded fixes on top of the latest remote branch without force-pushing or losing security commits.
2. Obtain successful Laravel 11/12/13 hosted/fresh-app evidence and execute File/Database/Redis cross-process proof races plus offline-ZIP install acceptance.
3. Restore full browser/native CI evidence for the exact candidate and generate verified release artifacts.
4. Run security/load/accessibility/solver benchmarks and tune policies from measured results.
5. Publish Composer/npm packages only after licensing, signing and release approval are decided.
6. Add thin framework wrappers only where they materially improve forms/SSR ergonomics; do not duplicate CAPTCHA logic outside the core Web Component.
7. Add optional WebAuthn later as a separately reviewed account step-up mechanism. Keep camera-based liveness outside the core CAPTCHA package.
