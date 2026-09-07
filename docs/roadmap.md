# Acceptance status and remaining work

This document separates implemented capabilities from release assurance. `validation.json` and the exact CI run are authoritative for test execution; a checkbox here is not a substitute for evidence.

## Implemented in this candidate

- [x] Go HTTP engine; explicit private/public API boundaries; trusted host session creation.
- [x] Local SQLite state, revision CAS, persistent counters, expiry cleanup and schema-version guard.
- [x] Text, slider, free drag, rotation, ordered click, English audio, math, logic and PoW generators/verifiers.
- [x] Backend-owned antiAI profile with bounded PoW and experimental raster noise; no AI-proof claim.
- [x] Arabic/English web UI, RTL, themes, keyboard controls, cancellation, expiry and native form association.
- [x] Generic API, server clients for Node/PHP/Python/.NET, and framework integration recipes.
- [x] Native build/packaging workflow, checksum manifest, dependency inventory and local notices.
- [x] Tests for replay, concurrent state transitions, wrong contexts, expiry, refresh budgets, origin checks, malformed inputs, offline browser requests and real widget-to-engine integration.

## Must be verified before a customer production rollout

- [ ] Successful **full** CI for the exact source commit (SQLite, Lit build, real browsers, .NET and native compile). Local dependency-free tests alone do not satisfy this gate.
- [ ] Customer-native Windows/macOS/Linux runtime acceptance and first-boot packet capture with Internet blocked.
- [ ] Customer reverse-proxy/TLS/ACL, OS data permissions, secret provisioning and rollback recovery checks.
- [ ] Independent security review, solver/adversarial benchmark, load/resource-exhaustion test and human usability/accessibility assessment.
- [ ] Owner's package licensing choice, release approval and an authenticated signing/update process. Checksums alone are not signatures.

## Explicitly not implemented

- Biometric/face/voice identity or liveness, WebAuthn/passkey enrollment and verification.
- ML/adaptive risk scoring, behavioral fingerprinting, global IP reputation or bot-farm detection.
- Proven anti-AI fonts, model-specific resistance guarantees, motion-font challenges.
- Arabic-spoken audio assets and an audited accessible alternative route for every user group.
- Published framework wrapper packages, Angular ControlValueAccessor and native mobile UI.
- Shared multi-node storage adapters, distributed deployments, signed installers/auto-updates or offline source toolchain kits.

## Next implementation order

First close customer release-assurance gates and use measured usability/attack results to tune policy. Then add compiled consumer fixtures and thin framework wrappers. Introduce shared-state deployment only when an actual multi-node requirement exists. Add optional WebAuthn as a separately reviewed account step-up mechanism rather than pretending it is anonymous human detection. Keep camera-based liveness outside the core CAPTCHA package.
