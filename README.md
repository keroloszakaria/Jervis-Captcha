# Jervis Captcha

**An offline-first CAPTCHA platform with an embedded Laravel engine, an optional native engine, and a framework-neutral web package.**

Laravel/PHP can run the verification engine in-process through `jervis/captcha`; other backends can use the Go + SQLite native engine. Both modes use the same TypeScript + Lit browser widget. No CDN, cloud verification API, telemetry, remote activation or runtime font downloads are required.

> **0.2.0-alpha.1 development branch.** This is a functional integration candidate, not an independently audited anti-bot service. `isAntiAI` selects additional local work and experimental rendering; it does not make a challenge AI-proof. Read [SECURITY.md](SECURITY.md) before deployment. Biometric verification is not implemented.

## What is implemented

| Area | Implementation |
|---|---|
| Challenges | Raster text, puzzle slider, free-drag puzzle, arrow rotation, ordered click, English digit audio, math, arithmetic sequences, bounded proof of work |
| Presentation | Inline, checkbox entry, invisible for PoW; human challenges remain visible |
| Trust model | Host-created sessions, site/action/session binding, server-owned policies, short-lived opaque proofs, atomic single-use consumption |
| Persistence | Laravel lock-capable cache in embedded mode; SQLite WAL and compare-and-swap in native mode |
| Browser UX | Arabic/English UI, RTL, light/dark/system themes, keyboard controls, form association, cancellation and expiry events |
| Integrations | `jervis/captcha` Composer package, `@jervis/captcha` browser package, plus generic HTTP/OpenAPI and Node/PHP/Python/.NET native-engine clients |
| Deployment | In-process Laravel/PHP package or native executable; both can run without Internet access at runtime |

Audio currently speaks **English digits**, including when the UI is Arabic. Math and logic are explicitly low-assurance types and cannot be enabled in an `isAntiAI` policy. PoW raises computational cost; it is not proof of a human. Camera, face recognition, passkeys and behavioral identity profiles are deliberately absent from this release.

## Embedded Laravel package

Laravel can run Jervis Captcha entirely inside the application process: no Go daemon, second port or external verification endpoint. The Composer package owns the engine, routes, middleware, cache locking and Blade component; a separate `@jervis/captcha` package contains the framework-neutral browser widget.

```bash
composer require jervis/captcha
php artisan jervis-captcha:install
```

```php
Route::post('/signup', SignupController::class)->middleware('jervis.captcha:signup');
```

```blade
<x-jervis-captcha action="signup" />
```

See [Embedded PHP / Laravel](docs/php-embedded.md). The Go/native engine remains available for non-PHP and centralized deployments.

## Run the native distribution

Download an `offline-distributions` artifact from a **successful** repository CI run. Select the archive for your OS/architecture, extract it, and run:

```sh
./jervis-captcha init --config jervis.json
./jervis-captcha serve --config jervis.json --demo
```

Windows PowerShell:

```powershell
.\jervis-captcha.exe init --config jervis.json
.\jervis-captcha.exe serve --config jervis.json --demo
```

Open `http://127.0.0.1:8090`. Complete a challenge and click **Submit protected action**. The host demo consumes the proof; replaying it fails. Demo is permitted only on a literal loopback listener and is disabled unless `--demo` is supplied.

`init` generates a unique site secret and refuses to overwrite an existing configuration. Keep the configuration private. For production, omit `--demo`, configure allowed application origins/actions and use the integration described below. Do not expose the private API to browsers.

## Build from source

Build prerequisites: Go 1.26.8, Node 22.12+ and npm. The first bootstrap also requires Python 3 and eSpeak **only if the generated audio file is absent**. Verified CI commits the generated assets and lockfiles on the implementation branch; subsequent builds use them.

```sh
# Initial checkout without a lockfile: npm install. Thereafter:
npm ci
# Only when internal/captcha/audio_samples.go is absent:
python3 scripts/audio-pack.py
npm run validate:web
go mod download
go test -race ./...
go vet ./...
go build -trimpath -o jervis-captcha ./cmd/jervis-captcha
```

A connected **build host** resolves dependencies. A released executable needs no dependency installer or connection. For an offline source rebuild, additionally transfer the exact Go toolchain, module cache/vendor directory, Node/npm cache and pinned dependencies; a git checkout alone is not an offline build kit.

## Integration modes

### Laravel embedded: package-owned orchestration

The consuming Laravel project does not create CAPTCHA session endpoints or call a verification service. The package registers its same-application routes, derives the binding from the Laravel session, creates challenges, verifies answers and atomically consumes the proof. Use only the component and matching middleware action:

```blade
<x-jervis-captcha action="signup" />
```

```php
Route::post('/signup', SignupController::class)->middleware('jervis.captcha:signup');
```

For a SPA using the same Laravel backend, import `@jervis/captcha` and render `<jervis-captcha action="signup" endpoint="/jervis-captcha/">`. The widget bootstraps its package-managed session automatically.

### Native/universal engine

Non-PHP or centralized deployments keep the explicit host-backend contract: the trusted backend creates a session, passes only its opaque session token to the widget, and atomically verifies/consumes the final proof through the private native API. See [Browser/framework integration](docs/integrations.md).

Keep normal authentication, CSRF protection, authorization, business rate limits and transaction idempotency. CAPTCHA is an additional control, not a replacement.

## Documentation and evidence

- [Quick start and deployment](docs/quickstart.md)
- [API and OpenAPI](docs/api.md) / [machine-readable contract](docs/openapi.json)
- [Browser/framework integration](docs/integrations.md)
- [Architecture and threat model](docs/architecture.md)
- [Air-gapped operations](docs/offline.md)
- [Acceptance status and remaining work](docs/roadmap.md)
- `validation.json`, once produced by successful CI, identifies the source commit and actual test results.

`npm run docs:dev` serves local documentation; `npm run docs:build` creates the documentation site. Packages have **not** been published to npm/NuGet/PyPI/Packagist. Public licensing is not yet selected; third-party dependencies retain their own licenses.
