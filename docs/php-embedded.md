# Embedded PHP / Laravel package

Jervis Captcha can run **inside the Laravel PHP process**. This mode does not start the Go executable, call a second verification service, or require another port, container or daemon. The package owns challenge generation, the verification state machine, proof consumption, Laravel routes, cache locks and the browser asset.

## Intended Laravel usage

The repository root contains the Composer package manifest so a tagged/released repository can be consumed as `jervis/captcha`; `packages/php/composer.json` mirrors the PHP subtree for offline/split packaging. The package targets Laravel 11, 12 and 13 with PHP 8.2+.

```bash
composer require jervis/captcha
php artisan jervis-captcha:install
php artisan jervis-captcha:doctor
```

Protect an application action:

```php
Route::post('/signup', SignupController::class)
    ->middleware('jervis.captcha:signup');
```

For Blade, the complete browser integration is:

```blade
<x-jervis-captcha action="signup" />
```

The component serves the bundled local browser widget, obtains its verification session from package-owned routes, solves the configured challenge and submits the one-use proof. The consuming application must not duplicate answer checking, proof parsing, replay prevention or CAPTCHA session creation.

## Proof field name

Embedded mode uses one **server-owned** proof field name for the Blade component, SPA widget and middleware:

```env
JERVIS_CAPTCHA_FIELD_NAME=jervisCaptchaToken
```

A custom value such as `captchaProof` is supported. The package validates that it starts with a letter/underscore, contains only letters, digits, `_` or `-`, and is at most 64 characters. Managed-session responses include the non-sensitive `fieldName`, and `@jervis/captcha` applies it to its form-associated custom element automatically. A same-origin SPA therefore does not need to duplicate this configuration in frontend code.

Do not set a different `name` on individual Blade components. The `X-Jervis-Captcha` request header remains an alternative transport for API submissions. `php artisan jervis-captcha:doctor` validates the configured field name before deployment.

## SPA / frontend package

Install the framework-neutral browser package:

```bash
npm install @jervis/captcha
```

```ts
import '@jervis/captcha';
```

```html
<jervis-captcha action="signup"></jervis-captcha>
```

When a widget has an `action` and no explicit backend-created `sessionToken`, it uses the embedded Laravel prefix `/jervis-captcha/` automatically. Set `endpoint` only when the Laravel application is mounted under a different base path or the package route prefix was customized.

Vue, Nuxt, React, Next, Angular and plain HTML can use the same custom element. The browser sends same-origin session credentials only; it never receives the application secret, expected answer or trusted binding. For Laravel CSRF, the widget uses its explicit package-provided token in Blade, a standard `meta[name=csrf-token]`, or Laravel's `XSRF-TOKEN` cookie. CSRF material is never forwarded cross-origin. Cross-origin managed session creation is rejected; centralized/native deployments instead use a trusted backend-created opaque `sessionToken`.

## Internal routes are package infrastructure

Embedded mode registers same-application routes under `/jervis-captcha` by default:

- `POST /jervis-captcha/session/{action}`
- `POST /jervis-captcha/v1/challenges`
- `POST /jervis-captcha/v1/answers`
- `GET /jervis-captcha/assets/jervis-captcha.js`

These are **not a separate Jervis service**. They execute the Composer package inside the host Laravel request and use the host application's session/cache. A browser still has to request a server-generated challenge and submit an answer to trusted server-side code; hiding these package routes would not remove that protocol requirement.

The three verification POST routes always run under Laravel's `web` middleware so session binding and CSRF protection remain active. `web` cannot be removed from the embedded managed route configuration. The browser asset route is deliberately separate because serving a local static JavaScript file does not require opening a Laravel session; it is returned with `nosniff` and same-origin resource-policy headers.

The route prefix is validated as one or more safe path segments. Empty/root prefixes, query-like values, duplicate separators, and `.`/`..` traversal segments are rejected rather than registering Jervis routes in an unexpected location.

The browser may provide an action name such as `signup`, but cannot provide binding material, expected answers, TTLs, `isAntiAI`, challenge policy or a concrete challenge type.

## Server-owned policies

`default_policy` is the server-owned baseline and applies to any syntactically valid action. This provides a zero-config experience for actions such as `signup`, `login` and `forgot-password`.

`actions` contains **partial overrides** over that project default. For example:

```php
'default_policy' => [
    'types' => ['text', 'slider', 'rotate'],
    'accessibilityTypes' => ['audio'],
    'isAntiAI' => true,
    'workBits' => 14,
    'minSolveMs' => 400,
    'sessionTtlSeconds' => 300,
    'challengeTtlSeconds' => 120,
    'proofTtlSeconds' => 60,
    'maxAttempts' => 3,
    'maxRefreshes' => 4,
    'sessionsPerMinute' => 120,
],
'actions' => [
    // Inherits every other value from default_policy.
    'login' => ['types' => ['slider', 'rotate']],
],
```

Set `default_policy` to `null` when the application should use an explicit action allowlist only. In that mode, only keys declared under `actions` are accepted, unknown actions return `invalid_context`, and every action array must be a complete policy instead of inheriting hidden library defaults.

Policy parsing is strict: unknown keys and incorrectly typed values are rejected instead of being silently coerced by PHP. Outstanding sessions carry a snapshot of the effective policy. A policy change invalidates those sessions rather than silently verifying them under changed rules.

## Accessible variant

Managed clients may request only the abstract variants:

```text
standard
accessible
```

They cannot request `audio`, `pow`, `math` or any other concrete type. `types` and `accessibilityTypes` are selected exclusively by the server-owned policy.

The default embedded policy uses `accessibilityTypes => ['audio']`. The normal session response exposes only `accessibilityAvailable: true|false` plus the non-sensitive proof `fieldName`; it does not expose the policy or list of enabled challenge types. When available, the widget can show **Use an accessible challenge / استخدم تحديًا ميسّرًا** and start a fresh accessible session.

Set an action's `accessibilityTypes` to `[]` to disable that variant. Audio is an alternative modality, not an anti-AI guarantee or complete accessibility coverage for every disability/user group.

## Request and session budgets

The engine applies global, per-action, global-per-binding and per-action-per-binding **session issuance** limits. Challenge refreshes and answer attempts are additionally bounded inside each verification session.

Embedded Laravel routes also enforce action-independent request budgets before session creation, challenge issue and answer verification. This prevents callers from bypassing endpoint throttling by continuously changing valid action names or sending large volumes of random session tokens.

Defaults:

- `JERVIS_CAPTCHA_GLOBAL_REQUESTS_PER_MINUTE=5000`
- `JERVIS_CAPTCHA_SESSIONS_PER_BINDING_PER_MINUTE=30`
- `JERVIS_CAPTCHA_CHALLENGES_PER_BINDING_PER_MINUTE=30`
- `JERVIS_CAPTCHA_ANSWERS_PER_BINDING_PER_MINUTE=60`

The per-binding key is derived from the trusted Laravel session, not a browser-provided identifier. Values outside `1..100000` or values with incorrect PHP types are invalid configuration and verification fails closed; `jervis-captcha:doctor` reports the problem.

All package-owned verification POST routes also have a fixed **32 KiB request-body guard** before controller/challenge logic. The answer endpoint then validates a bounded schema: only `text`, `x`, `y`, `angle` and up to three `points` are accepted, with small numeric/text limits and a bounded numeric PoW nonce. These controls supplement normal application/WAF/rate-limit controls; they do not replace them.

## State and atomicity

The default Laravel adapter uses the application's Cache Manager plus Laravel atomic locks. A proof is consumed under the same session-state lock so two concurrent requests cannot both consume it. State writes fail closed if persistence fails.

Rendered PNG/WAV challenge payloads are returned to the requesting browser but are not kept in the cache-backed session state. Persistence retains only verification metadata such as challenge id/work, expected answer and lifecycle state, reducing cache memory/storage pressure.

Use a persistent, lock-capable cache. The `array` and `null` stores are rejected by `jervis-captcha:doctor` because CAPTCHA state must survive across requests. On multi-node deployments, all nodes must use the same shared lock-capable cache backend; independent local stores are unsafe for one-use proofs.

The focused acceptance workflow is configured to run cross-process one-use-proof races against Laravel **file, database and Redis** cache stores. A passing release must demonstrate exactly one successful consume and one rejected replay for the same proof. Merely defining this CI job is not evidence that it passed.

`php artisan jervis-captcha:doctor` checks PHP/GD/zlib, secure randomness, secret length, proof field name, route configuration, engine configuration, request budgets, embedded digit-audio/browser assets and a real cache lock/write/read cycle.

## Embedded audio

The PHP package uses the **same generated English digit PCM samples** as the native Go engine. During the canonical build, `scripts/copy-assets.mjs` copies the generated native audio payload into `packages/php/resources/audio/digits.asset`, and CI compares the files byte-for-byte.

PHP decodes the local gzip/JSON payload and assembles an 8 kHz mono unsigned 8-bit WAV on the server. The browser receives only the final `data:audio/wav;base64,...` payload. It does not receive the answer text for browser TTS, and there is no CDN, speech API or runtime synthesizer dependency.

Arabic-UI users may enter Arabic or Western numerals because the verifier normalizes both, but the spoken audio is English digits in this release. Audio corruption/missing assets fail closed rather than triggering a remote fallback.

## Air-gapped package delivery

A successful embedded-package release build produces two runtime archives from the exact browser/audio artifacts accepted by package tests:

- `jervis-captcha-php-<version>.zip` — Composer-compatible PHP package contents with browser and digit-audio assets.
- `jervis-captcha-frontend-<version>.tgz` — npm-compatible frontend package for SPA projects.
- `manifest.json` and `SHA256SUMS` — release metadata and integrity hashes.

The packager enforces a minimal runtime surface. Composer receives only the compiled widget + third-party notices under its browser dist, while the npm package receives only the compiled widget, declarations, notices and package metadata. Demo HTML/JS/CSS and stale fallback bundles cause packaging to fail instead of leaking into release archives.

The archives are generated by `python3 scripts/package-embedded.py`; the packaging step itself uses local files and the Python standard library. Dependency resolution and the canonical TypeScript build happen on a connected build machine **before** the artifact is transferred into an isolated environment. The VCS Composer manifest intentionally has no hard-coded version; the archive builder validates the common Jervis Captcha version and injects it only into the offline artifact's `composer.json`.

The focused workflow is also configured to create a fresh Laravel application, disable Composer networking, install the generated PHP ZIP from a local Composer `artifact` repository, and run Jervis install/doctor checks. A production release should claim this only after a successful workflow or equivalent acceptance evidence exists.

For an offline Laravel environment, place the verified PHP ZIP in a local Composer artifact repository directory, for example `packages/`, and configure the consuming application's Composer repository once:

```json
{
  "repositories": [
    { "type": "artifact", "url": "packages" }
  ]
}
```

Then install without contacting Packagist:

```bash
composer require jervis/captcha:0.2.0-alpha.1 --no-interaction
php artisan jervis-captcha:install
php artisan jervis-captcha:doctor
```

Composer still needs all transitive Laravel dependencies to already exist in the customer's offline Composer cache/repository. The Jervis ZIP deliberately does not vendor the host application's Laravel dependencies.

For a separate SPA, transfer the frontend tarball and install it from disk:

```bash
npm install ./packages/jervis-captcha-frontend-0.2.0-alpha.1.tgz
```

No CDN or remote runtime download is required after deployment. Verify `SHA256SUMS` before importing archives into a controlled environment; authenticated signing remains a later production release gate.

## Embedded challenge scope

The embedded PHP engine implements raster text, slider, free drag, rotation, ordered click, English digit audio, math, logic and bounded proof-of-work.

`isAntiAI` remains an experimental hardening profile. It is not a claim that a challenge is AI-proof.
