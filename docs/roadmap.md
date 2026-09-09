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
- [x] Strict adversarial solver tooling: exact corpus coverage validation, shell-less local command adapters with sanitized environment/time/output bounds, deterministic cross-solver comparison and CI self-test. This is tooling, not multi-solver security evidence by itself.
- [x] Native build/packaging workflow plus minimal Composer/npm offline packaging with checksum manifest and local third-party notices.
- [x] Fresh Laravel consumer fixture, cross-process File/Database/Redis proof-race fixtures and network-disabled local-Composer-ZIP install gates are defined in the focused workflow.
- [x] Tests defined for replay, concurrent state transitions, wrong contexts, expiry, refresh/attempt budgets, request budgets, policy changes, origin checks, malformed inputs, type forcing, accessible audio fallback, offline browser requests and real widget-to-engine integration across Chromium, Firefox and WebKit.
- [x] Native Windows/Linux/macOS acceptance jobs and a loopback-only Linux network namespace gate. Execution results are recorded by CI; configuring a job alone is not a pass.

## Development validation already observed

A Windows development machine has executed the embedded candidate with PHP 8.4 / Laravel 13 dependencies. The observed local checks included TypeScript compilation, 19/19 Vitest tests, canonical Vite packaging, Composer manifest validation, Composer dependency audit with no advisories reported at that run, 57/57 PHP/Laravel tests with 588 assertions, embedded APP_KEY-fallback/dedicated-secret command coverage, deterministic embedded ZIP/TGZ generation and a successful npm dry-run package containing only the intended runtime files.

Fresh consuming applications were also exercised locally for Laravel 12 and Laravel 13. In both generations, package discovery, `jervis-captcha:install`, `jervis-captcha:doctor`, the package-owned Feature consumer (3/3 tests, 23 assertions), and a real HTTP cookie/CSRF flow all passed. The real HTTP flow used the production Laravel session binding and verified session creation, PoW solving, one-use proof consumption, replay rejection, concrete-type forcing rejection and the server-owned accessible audio variant.

Cross-process one-use-proof races also passed against Laravel File, SQLite-backed Database and Redis cache stores: each race produced exactly one `consumed` result and one `rejected` result for the same proof.

The generated `jervis-captcha-php-0.2.0-alpha.1.zip` was installed into a clean Laravel 12 application from a local Composer `artifact` repository with `COMPOSER_DISABLE_NETWORK=1`. Composer resolved Jervis from the local ZIP, package discovery completed, and `jervis-captcha:install` plus `jervis-captcha:doctor` passed on the installed artifact. Network-backed audit was intentionally not part of that isolated import step because dependency audit is performed while the build host is connected.

The same offline-installed Laravel 12 artifact was then exercised in headless Chromium. A Blade form containing only `<x-jervis-captcha action="signup" presentation="invisible" />` completed the package-managed PoW flow and protected form submit with zero external browser requests; the package-served JavaScript also exposed the expected `nosniff` and same-origin CORP headers. Separately, the generated frontend TGZ supplied `dist/jervis-captcha.js` to a same-origin SPA page containing only the module import and `<jervis-captcha action="signup" presentation="invisible"></jervis-captcha>`; that packaged SPA flow also completed a protected submit with zero external requests.

A bounded development load/resource smoke was also run locally. Direct procedural generation over 20 samples showed interactive raster challenges in roughly the 5–9 ms mean range on that machine (Click was the heaviest observed p95 at about 17 ms), with about 4 MiB CLI peak memory for the direct generator smoke. Under Laravel multi-process Click generation, Redis with four workers processed 80 session+issue flows at about 123 flows/second, with per-worker session+issue means around 10–15 ms and about 22 MiB peak memory per worker. SQLite-backed Database cache with two workers measured about 21.7 flows/second (roughly 68–80 ms/flow), while File cache with two workers measured about 15.1 flows/second (roughly 96–117 ms/flow). These are development-machine smoke numbers, not capacity/SLA claims. File-store retry polling remains conservative after aggressive polling reproduced Windows filesystem-lock errors; Redis and Database retain faster lock polling and all three stores re-passed the concurrent one-use-proof race.

The adversarial text harness now binds prediction files to a corpus id, withholds expected answers from the solver manifest, rejects missing/duplicate/foreign predictions, and compares the attacker-best solver plus preprocessing variant. A strict 400-record corpus (50 standard + 50 `isAntiAI` challenges, each as original, 2x resize, JPEG-65 and grayscale/contrast) was run through both Windows Media OCR and Tesseract 5.5.3. The first two-solver run exposed a real regression: Tesseract's attacker-best exact rate was 6% for `isAntiAI` versus 4% for standard, so the PHP hard renderer was changed from extra random pixels to bounded crossing wave/decoy strokes. On an independent fresh 400-record corpus after that change, both OCR families produced zero exact matches for both profiles; hardened-text maximum character accuracy fell to about 1.0% with Windows OCR and 0.33% with Tesseract.

A separate complete 40-record cohort (5 challenges/profile x 4 preprocessing variants) was then run through a local Qwen3.5 9B vision model via LM Studio with reasoning disabled. The VLM solved **17/20 standard attempts (85%)** and **10/20 hardened-text attempts (50%)**; attacker-best grayscale exact rates were **100%** and **80%** respectively. That result is treated as a design failure of text-as-antiAI, not as a benchmark win: both PHP and native default hard pools now use slider/drag/rotate/click, and primary antiAI policy validation rejects `text`. Sample hardened-text images were manually spot-checked as readable, but this is **not** a human usability/accessibility study. The VLM cohort is small and model-specific, so broader VLM families, independent review and human measurements remain release gates.
This local evidence is useful for development but **does not replace** hosted CI, the full browser/native matrix or production/customer acceptance below.

## Must be verified before a customer production rollout

- [ ] Obtain a successful **full** CI run for the exact candidate source commit. Current GitHub-hosted jobs have repeatedly failed before checkout or any test step starts (`runner_id=0`, `steps=[]`), so those runs are not evidence of implementation failure or success.
- [ ] Obtain a successful **Embedded PHP package** hosted run for the exact candidate: canonical browser build, Laravel 12/13 package tests, fresh-app installs, Composer audit, File/Database/Redis cross-process proof races, offline archive generation and network-disabled local-ZIP installation.
- [x] Local final-artifact browser acceptance passed for both Blade (Composer ZIP asset) and a same-origin SPA (frontend TGZ asset), including protected submit and zero-egress checks. Hosted/equivalent release evidence is still required for the exact release candidate.
- [ ] Perform load/resource-exhaustion tests for PHP-FPM workers, GD image generation, audio generation, PoW, session creation and invalid-token traffic; tune defaults from measured results.
- [ ] Customer-native Windows/macOS/Linux runtime acceptance and first-boot packet capture with Internet blocked for native deployments.
- [ ] Customer reverse-proxy/TLS/ACL, OS/data permissions, secret provisioning and rollback recovery checks.
- [ ] Independent security review, broader **multi-solver OCR/VLM** adversarial benchmark and human usability/accessibility assessment. Local evidence now includes Windows OCR, Tesseract and one small Qwen3.5 vision cohort, but that is not independent or broad enough to close this gate.
- [ ] Owner's package licensing choice, release approval and an authenticated signing/update process. Checksums alone are not signatures.

## Explicitly not implemented

- Biometric/face/voice identity or liveness, WebAuthn/passkey enrollment and verification.
- ML/adaptive risk scoring, behavioral fingerprinting, global IP reputation or bot-farm detection.
- Proven anti-AI fonts, model-specific resistance guarantees or motion-font challenges.
- Arabic-spoken audio assets and a fully audited accessibility route for every disability/user group. The current accessible variant is an English digit-audio alternative, not universal accessibility coverage.
- Published Vue/React/Angular wrapper packages, Angular ControlValueAccessor and native mobile UI. The Web Component itself remains the supported cross-framework surface.
- Signed installers/auto-updates or offline source toolchain kits.

## Next implementation order

1. Restore successful hosted **Embedded PHP** and full CI evidence for the exact candidate; current jobs are failing before runner steps begin.
2. Extend adversarial coverage from the current text probes to the **actual default hard pool** (slider/drag/rotate/click) with broader VLM/automation agents, then add human solve-success/time/retry/accessibility measurements; keep `isAntiAI` experimental until that evidence exists.
3. Extend load/resource-exhaustion coverage to PHP-FPM/GD/audio/PoW/session creation and invalid-token traffic, then tune limits from measured results.
4. Complete customer-native Windows/macOS/Linux, blocked-egress, reverse-proxy/TLS/ACL, permissions, secret-provisioning and rollback acceptance.
5. Decide licensing, authenticated signing/update policy and release approval before publishing Composer/npm packages.
6. Add thin framework wrappers only where they materially improve forms/SSR ergonomics; do not duplicate CAPTCHA logic outside the core Web Component.
7. Add optional WebAuthn later as a separately reviewed account step-up mechanism. Keep camera-based liveness outside the core CAPTCHA package.
