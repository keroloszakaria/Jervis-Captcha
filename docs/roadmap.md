# Acceptance status and remaining work

This document separates implemented capabilities from release assurance. `validation.json`, successful CI runs and explicitly recorded local acceptance are authoritative for test execution; a checkbox here is not a substitute for evidence.

## Implemented in this candidate

- [x] Go HTTP engine; explicit private/public API boundaries; trusted host session creation.
- [x] Local SQLite state, revision CAS, persistent counters, expiry cleanup and schema-version guard.
- [x] Embedded PHP engine that runs in-process in Laravel with no separate Jervis service, daemon, port or container.
- [x] Laravel package-owned routes, trusted session binding, atomic cache locks, one-use proof consumption, middleware, Blade component, install/doctor commands and fail-closed storage behavior.
- [x] Laravel 12/13 Composer compatibility constraints with a dedicated Testbench/fresh-app matrix for the supported generations. Laravel 11 is intentionally excluded after its security-fix window ended on March 12, 2026.
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
- [x] Embedded secret domain separation plus optional `jervis-captcha:key` provisioning/rotation; APP_KEY remains the zero-config fallback and duplicate `.env` secret declarations fail closed.
- [x] Native build/packaging workflow plus minimal Composer/npm offline packaging with checksum manifest and local third-party notices.
- [x] Fresh Laravel consumer fixture, cross-process File/Database/Redis proof-race fixtures and network-disabled local-Composer-ZIP install gates are defined in the focused workflow.
- [x] Tests defined for replay, concurrent state transitions, wrong contexts, expiry, refresh/attempt budgets, request budgets, policy changes, origin checks, malformed inputs, type forcing, accessible audio fallback, offline browser requests and real widget-to-engine integration across Chromium, Firefox and WebKit.
- [x] Native Windows/Linux/macOS acceptance jobs and a loopback-only Linux network namespace gate. Execution results are recorded by CI; configuring a job alone is not a pass.

## Development validation already observed

A Windows development machine has executed the embedded candidate with PHP 8.4 / Laravel 13 dependencies. The observed local checks included TypeScript compilation, 19/19 Vitest tests, canonical Vite packaging, Composer manifest validation, Composer dependency audit with no advisories reported at that run, 54/54 PHP/Laravel tests with 582 assertions, embedded APP_KEY-fallback/dedicated-secret command coverage, deterministic embedded ZIP/TGZ generation and a successful npm dry-run package containing only the intended runtime files.

Fresh consuming applications were also exercised locally for Laravel 12 and Laravel 13. In both generations, package discovery, `jervis-captcha:install`, `jervis-captcha:doctor`, the package-owned Feature consumer (3/3 tests, 23 assertions), and a real HTTP cookie/CSRF flow all passed. The real HTTP flow used the production Laravel session binding and verified session creation, PoW solving, one-use proof consumption, replay rejection, concrete-type forcing rejection and the server-owned accessible audio variant.

Cross-process one-use-proof races also passed against Laravel File, SQLite-backed Database and Redis cache stores: each race produced exactly one `consumed` result and one `rejected` result for the same proof.

The generated `jervis-captcha-php-0.2.0-alpha.1.zip` was installed into a clean Laravel 12 application from a local Composer `artifact` repository with `COMPOSER_DISABLE_NETWORK=1`. Composer resolved Jervis from the local ZIP, package discovery completed, and `jervis-captcha:install` plus `jervis-captcha:doctor` passed on the installed artifact. Network-backed audit was intentionally not part of that isolated import step because dependency audit is performed while the build host is connected.

The same offline-installed Laravel 12 artifact was then exercised in headless Chromium. A Blade form containing only `<x-jervis-captcha action="signup" presentation="invisible" />` completed the package-managed PoW flow and protected form submit with zero external browser requests; the package-served JavaScript also exposed the expected `nosniff` and same-origin CORP headers. Separately, the generated frontend TGZ supplied `dist/jervis-captcha.js` to a same-origin SPA page containing only the module import and `<jervis-captcha action="signup" presentation="invisible"></jervis-captcha>`; that packaged SPA flow also completed a protected submit with zero external requests.

A bounded development load/resource smoke was also run locally. Direct procedural generation over 20 samples showed interactive raster challenges in roughly the 5–9 ms mean range on that machine (Click was the heaviest observed p95 at about 17 ms), with about 4 MiB CLI peak memory for the direct generator smoke. Under Laravel multi-process Click generation, Redis with four workers processed 80 session+issue flows at about 123 flows/second, with per-worker session+issue means around 10–15 ms and about 22 MiB peak memory per worker. SQLite-backed Database cache with two workers measured about 21.7 flows/second (roughly 68–80 ms/flow), while File cache with two workers measured about 15.1 flows/second (roughly 96–117 ms/flow). These are development-machine smoke numbers, not capacity/SLA claims. File-store retry polling remains conservative after aggressive polling reproduced Windows filesystem-lock errors; Redis and Database retain faster lock polling and all three stores re-passed the concurrent one-use-proof race.

A preliminary **single-solver** text benchmark was also run with Windows Media OCR (`en-US`) over 50 fresh standard and 50 fresh `isAntiAI` text challenges, each tested as original PNG, 2x resize, JPEG quality 65 and grayscale/contrast preprocessing (400 solver attempts total). Windows OCR produced 4 exact matches across 200 standard attempts (2.0%) and 1 exact match across 200 `isAntiAI` attempts (0.5%). The defender-worst preprocessing variant reached 4% exact for standard and 2% for `isAntiAI`. This is not proof of AI resistance: it is one traditional OCR engine, variants reuse the same underlying challenges, and no human-usability or modern VLM cohort was measured. The benchmark harness is retained under `tests/adversarial/` so additional solvers can reuse the same corpus/evaluator.
This local evidence is useful for development but **does not replace** hosted CI, the full browser/native matrix or production/customer acceptance below.

## Must be verified before a customer production rollout

- [ ] Obtain a successful **full** CI run for the exact candidate source commit. Current GitHub-hosted jobs have repeatedly failed before checkout or any test step starts (`runner_id=0`, `steps=[]`), so those runs are not evidence of implementation failure or success.
- [ ] Obtain a successful **Embedded PHP package** hosted run for the exact candidate: canonical browser build, Laravel 12/13 package tests, fresh-app installs, Composer audit, File/Database/Redis cross-process proof races, offline archive generation and network-disabled local-ZIP installation.
- [x] Local final-artifact browser acceptance passed for both Blade (Composer ZIP asset) and a same-origin SPA (frontend TGZ asset), including protected submit and zero-egress checks. Hosted/equivalent release evidence is still required for the exact release candidate.
- [ ] Perform load/resource-exhaustion tests for PHP-FPM workers, GD image generation, audio generation, PoW, session creation and invalid-token traffic; tune defaults from measured results.
- [ ] Customer-native Windows/macOS/Linux runtime acceptance and first-boot packet capture with Internet blocked for native deployments.
- [ ] Customer reverse-proxy/TLS/ACL, OS/data permissions, secret provisioning and rollback recovery checks.
- [ ] Independent security review, **multi-solver OCR/VLM** adversarial benchmark and human usability/accessibility assessment. A preliminary Windows OCR benchmark exists but is not sufficient for this gate.
- [ ] Owner's package licensing choice, release approval and an authenticated signing/update process. Checksums alone are not signatures.

## Explicitly not implemented

- Biometric/face/voice identity or liveness, WebAuthn/passkey enrollment and verification.
- ML/adaptive risk scoring, behavioral fingerprinting, global IP reputation or bot-farm detection.
- Proven anti-AI fonts, model-specific resistance guarantees or motion-font challenges.
- Arabic-spoken audio assets and a fully audited accessibility route for every disability/user group. The current accessible variant is an English digit-audio alternative, not universal accessibility coverage.
- Published Vue/React/Angular wrapper packages, Angular ControlValueAccessor and native mobile UI. The Web Component itself remains the supported cross-framework surface.
- Signed installers/auto-updates or offline source toolchain kits.

## Next implementation order

1. Land the Laravel 12/13 support policy and the new fresh-consumer/real-HTTP acceptance fixtures on the implementation branch.
2. Obtain successful Laravel 12/13 hosted evidence for the same acceptance gates already observed locally.
3. Restore full browser/native CI evidence for the exact candidate and generate verified release artifacts.
4. Run security/load/accessibility/solver benchmarks and tune policies from measured results.
5. Publish Composer/npm packages only after licensing, signing and release approval are decided.
6. Add thin framework wrappers only where they materially improve forms/SSR ergonomics; do not duplicate CAPTCHA logic outside the core Web Component.
7. Add optional WebAuthn later as a separately reviewed account step-up mechanism. Keep camera-based liveness outside the core CAPTCHA package.
