# Jervis Captcha

**An offline-first CAPTCHA platform with an embedded Laravel engine, an optional native engine, and a framework-neutral web package.**

Laravel/PHP can run the verification engine in-process through `jervis/captcha`; other backends can use the Go + SQLite native engine. Both modes use the same TypeScript + Lit browser widget. No CDN, cloud verification API, telemetry, remote activation or runtime font downloads are required.

> **0.2.0-alpha.1 development branch.** This is a functional integration candidate, not an independently audited anti-bot service. `isAntiAI` selects additional local work and experimental rendering; it does not make a challenge AI-proof. Read [SECURITY.md](SECURITY.md) before deployment. Biometric verification is not implemented.

## What is implemented

| Area | Implementation |
|---|---|
| Challenges | Raster text, puzzle slider, free-drag puzzle, arrow rotation, ordered click, locally generated English digit audio, math, arithmetic sequences, bounded proof of work |
| Presentation | Inline, checkbox entry, invisible for PoW; human challenges remain visible |
| Accessibility | Server-owned accessible challenge pool; managed Laravel flow can offer a local audio alternative without letting the browser choose a concrete challenge type |
| Trust model | Host-created/managed sessions, action/session binding, server-owned policies, short-lived opaque proofs, atomic single-use consumption |
| Persistence | Laravel lock-capable persistent cache in embedded mode; SQLite WAL and compare-and-swap in native mode |
| Browser UX | Arabic/English UI, RTL, light/dark/system themes, keyboard controls, form association, cancellation, recovery and expiry events |
| Integrations | `jervis/captcha` Composer package, `@jervis/captcha` browser package, plus generic HTTP/OpenAPI and Node/PHP/Python/.NET native-engine clients |
| Deployment | In-process Laravel/PHP package or native executable; both can run without Internet access at runtime |

The embedded PHP engine now implements text, slider, drag, rotate, ordered click, English digit audio, math, logic and PoW. Audio uses the same generated local digit samples as the native engine; no runtime TTS or speech API is used. Math and logic are explicitly low-assurance types and cannot be enabled in an `isAntiAI` policy. PoW raises computational cost; it is not proof of a human. Camera, face recognition, passkeys and behavioral identity profiles are deliberately absent from this release.

## Embedded Laravel package

Laravel 12 and 13 can run Jervis Captcha entirely inside the application process on PHP 8.2+: no Go daemon, second port or external verification endpoint. The Composer package owns the engine, routes, middleware, cache locking and Blade component; `@jervis/captcha` contains the framework-neutral browser widget for SPA projects.

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

For a same-origin SPA:

```ts
import '@jervis/captcha';
```

```html
<jervis-captcha action="signup"></jervis-captcha>
```

That is the default integration surface. The package creates the verification session, generates the challenge, validates the answer and consumes the one-use proof. Set `endpoint` only when the Laravel application/package routes use a non-default base path.

The managed browser flow supports only the abstract variants `standard` and `accessible`. The browser cannot force `audio`, `pow`, `math`, `isAntiAI`, binding material or any other policy field. When the server-owned policy exposes an accessible pool, the widget can offer **Use an accessible challenge / استخدم تحديًا ميسّرًا** and request a fresh accessible session. The default embedded policy maps that variant to local English digit audio.

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

Build prerequisites: Go 1.26.8, Node 22.12+ and npm. The first native bootstrap also requires Python 3 and eSpeak **only if the generated native audio file is absent**. Verified native CI commits generated assets and lockfiles on implementation branches; subsequent builds use them.

```sh
npm ci
npm run validate:web
go mod download
go test -race ./...
go vet ./...
go build -trimpath -o jervis-captcha ./cmd/jervis-captcha
```

For embedded packages, `npm run build` produces the browser package artifact used by both Composer and npm distributions, and `python3 scripts/package-embedded.py` creates offline Composer/npm-compatible archives after validation. The package build also copies the generated native digit-audio payload byte-for-byte into the Composer artifact so both runtimes use the same local source samples.

A connected **build host** resolves dependencies. Released runtime artifacts need no runtime dependency installer or Internet connection. For an offline source rebuild, additionally transfer the exact toolchains/caches or an internal dependency repository; a git checkout alone is not an offline build kit.

## Integration modes

### Laravel embedded: package-owned orchestration

The consuming Laravel project does not create CAPTCHA session endpoints or call a verification service. The package registers its same-application routes, derives the binding from the Laravel session, creates challenges, verifies answers and atomically consumes the proof. Use only the component and matching middleware action:

```blade
<x-jervis-captcha action="signup" />
```

```php
Route::post('/signup', SignupController::class)->middleware('jervis.captcha:signup');
```

For a same-origin SPA, import `@jervis/captcha` and render `<jervis-captcha action="signup"></jervis-captcha>`. Managed session creation is intentionally same-origin. The default endpoint is `/jervis-captcha/`; override it only for a custom base path/prefix.

### Native/universal engine

Non-PHP or centralized deployments keep the explicit host-backend contract: the trusted backend creates a session, passes only its opaque session token to the widget, and atomically verifies/consumes the final proof through the private native API. See [Browser/framework integration](docs/integrations.md).

Keep normal authentication, CSRF protection, authorization, business rate limits and transaction idempotency. CAPTCHA is an additional control, not a replacement.

## Offline package delivery

The focused embedded pipeline is designed to produce:

- `jervis-captcha-php-0.2.0-alpha.1.zip` for a local Composer artifact repository.
- `jervis-captcha-frontend-0.2.0-alpha.1.tgz` for local npm installation.
- `manifest.json` and `SHA256SUMS` for integrity/import workflows.

See [Embedded PHP / Laravel](docs/php-embedded.md) for air-gapped installation details. Signed release artifacts remain a production release gate.

## Documentation and evidence

- [Embedded PHP / Laravel](docs/php-embedded.md)
- [Quick start and deployment](docs/quickstart.md)
- [API and OpenAPI](docs/api.md) / [machine-readable contract](docs/openapi.json)
- [Browser/framework integration](docs/integrations.md)
- [Architecture and threat model](docs/architecture.md)
- [Air-gapped operations](docs/offline.md)
- [Acceptance status and remaining work](docs/roadmap.md)
- `validation.json`, once produced by successful CI, identifies the source commit and actual test results.

`npm run docs:dev` serves local documentation; `npm run docs:build` creates the documentation site. Packages have **not** been published to npm/NuGet/PyPI/Packagist. Public licensing is not yet selected; third-party dependencies retain their own licenses.