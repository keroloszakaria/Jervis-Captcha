# Jervis Captcha

**An offline-first, self-hosted verification engine with a framework-neutral web widget.**

Go + SQLite on the customer's machine; TypeScript + Lit in the browser. No CDN, cloud verification API, telemetry, remote activation or runtime font downloads. The customer backend communicates with the engine through HTTP/JSON, regardless of its language or framework.

> **0.1.0-alpha.1.** This is a functional integration candidate, not an independently audited anti-bot service. `isAntiAI` selects additional local work and experimental rendering; it does not make a challenge AI-proof. Read [SECURITY.md](SECURITY.md) before deployment. Biometric verification is not implemented.

## What is implemented

| Area | Implementation |
|---|---|
| Challenges | Raster text, puzzle slider, free-drag puzzle, arrow rotation, ordered click, English digit audio, math, arithmetic sequences, bounded proof of work |
| Presentation | Inline, checkbox entry, invisible for PoW; human challenges remain visible |
| Trust model | Host-created sessions, site/action/session binding, server-owned policies, short-lived opaque proofs, atomic single-use consumption |
| Persistence | SQLite WAL, compare-and-swap transitions, persistent rate counters, expiry cleanup |
| Browser UX | Arabic/English UI, RTL, light/dark/system themes, keyboard controls, form association, cancellation and expiry events |
| Integrations | Generic HTTP/OpenAPI; Node/TypeScript, PHP, Python and .NET server clients; framework integration examples |
| Deployment | Native executable with embedded assets; no Go/Node/Python/GPU runtime dependency on the deployed machine |

Audio currently speaks **English digits**, including when the UI is Arabic. Math and logic are explicitly low-assurance types and cannot be enabled in an `isAntiAI` policy. PoW raises computational cost; it is not proof of a human. Camera, face recognition, passkeys and behavioral identity profiles are deliberately absent from this release.

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

## Integration in four steps

1. Your backend authenticates/initializes the application session and calls private `POST /v1/sessions` with `siteKey`, `action`, and a trusted `binding` (16–256 bytes). Send the site secret only in a Bearer authorization header. The binding must come from the backend session, never from an untrusted form field.
2. Give only the returned `sessionToken` to the browser. Load the local widget and assign its `sessionToken` **property**.
3. Submit the widget's proof token with the business form to your backend.
4. Your backend calls private `POST /v1/siteverify` with the expected site/action/binding. Proceed only on `success: true`. A timeout is a failure/indeterminate state, not permission to continue; do not retry proof consumption automatically.

```html
<script type="module" src="/captcha/assets/jervis-captcha.js"></script>
<jervis-captcha id="verification" endpoint="/captcha/" locale="ar" theme="light"></jervis-captcha>
```

```js
const element = document.querySelector('#verification');
// /api/captcha-session belongs to YOUR application backend and uses its session.
const response = await fetch('/api/captcha-session', { method: 'POST' });
if (!response.ok) throw new Error('Cannot create verification session');
element.sessionToken = (await response.json()).sessionToken;
element.addEventListener('jervis-success', event => {
  // Submit event.detail.token with the protected business form.
});
element.addEventListener('jervis-reset', () => { /* clear any previously held token */ });
element.addEventListener('jervis-expired', () => { /* clear token; ask backend for a new session */ });
```

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
