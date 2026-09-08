# Architecture and trust model

```text
Application backend --private session creation--> Go engine / SQLite
        |                                              ^
        +--opaque session token--> Browser widget -----+ (issue / solve)
        ^                              |
        +----business form + proof-----+
        |
        +--private verify + consume--> Go engine / SQLite
        |
        +--authorized business operation (only on success)
```

## Separation

`internal/captcha` implements the protocol independently of HTTP or SQLite. `internal/storage` supplies persistent state and limits. `internal/httpapi` enforces route boundaries, exact origins, strict JSON, body limits, response headers and bounded request concurrency. `internal/web` embeds the compiled browser code and demo assets. `cmd/jervis-captcha` owns local configuration and lifecycle.

The browser public client never receives a site secret or application session binding. The widget is one Lit web component; framework examples adapt events/properties without duplicating trust logic. Backend clients are thin HTTP integrations, not independent CAPTCHA engines.

## State machine

```text
new -> challenge -> solved -> consumed
          |            |
          |            +-- short proof expiry (cannot mint another proof)
          +-- refresh invalidates the previous challenge
          +-- failed answer spends the SAME session attempt budget
```

SQLite compare-and-swap requires the expected revision. Two concurrent solves cannot both issue a proof; two concurrent consumers cannot both accept it. Failure after proof consumption but before the business operation is not automatically recoverable across services. The host application needs business idempotency, not a CAPTCHA retry.

Session, challenge and proof expiry are separate. All are bounded by the session deadline. Proofs carry a hashed session locator plus a random credential; neither the expected answer nor a decodable signed answer payload is sent to the browser.

## `isAntiAI`

This trusted per-action flag enables experimental text noise and requires local proof of work for every selected type. It disallows math/logic, which are trivial automated tasks. It does not enable a model, GPU, cloud classifier, invisible identity profile or any guarantee of AI resistance. Rotation/drag/click puzzles remain low-entropy interactions whose practical value depends on rate limits and the protected operation.

There is no adaptive ML/risk-scoring engine yet. Selection is from the backend-configured allowlist; a trusted host backend can request a specific allowed type. The public widget cannot request a weaker type or alter policy fields.

## Data and privacy

SQLite stores short-lived challenge state, expected answers, hashed credentials and HMAC-protected application bindings. Expected answers and media are cleared after a successful solve. Expired records and counters are removed periodically. No fingerprint, raw IP history, camera frames, biometric template or analytics event is collected. Deletion from the logical database is not a forensic secure-erase guarantee; protect disk/backups at the OS level.

## Dependency choices

The deployed engine is a Go executable with the runtime and a CGO-free SQLite driver, plus embedded browser/audio assets. Development uses npm/Vite/Lit and optional VitePress documentation. The included raster glyphs are original bitmap definitions; no system or downloadable font file is required. Audio generation tooling is build-time only.
