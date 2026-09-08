# Embedded PHP / Laravel package

Jervis Captcha can run **inside the Laravel PHP process**. This mode does not start the Go executable, does not call a second verification service and does not need another port, container or daemon. The package owns the challenge generation, state machine, verification, proof consumption, Laravel routes, cache locks and browser asset.

## Intended Laravel usage

The repository root also contains the Composer package manifest, so a tagged/released repository can be consumed as `jervis/captcha`; `packages/php/composer.json` mirrors the PHP subtree for a future split package repository. The package supports Laravel 11, 12 and 13 with PHP 8.2+; the CI matrix resolves each Laravel generation against its matching Orchestra Testbench generation.

```bash
composer require jervis/captcha
php artisan jervis-captcha:install
php artisan jervis-captcha:doctor
```

Protect the application action:

```php
Route::post('/signup', SignupController::class)
    ->middleware('jervis.captcha:signup');
```

For a Blade form the complete browser integration is:

```blade
<x-jervis-captcha action="signup" />
```

The component serves the bundled local browser widget, obtains its verification session from package-owned routes, solves the configured challenge and submits the one-use proof as `jervisCaptchaToken`. The consuming project must not reproduce answer checking, proof parsing or replay logic.

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

Vue, Nuxt, React, Next and Angular can use the same custom element. The browser sends same-origin session credentials only; it never receives the application secret or expected CAPTCHA answer. For Laravel CSRF, the widget uses its explicit package-provided token in Blade, a standard `meta[name=csrf-token]`, or Laravel's `XSRF-TOKEN` cookie. CSRF material is never forwarded cross-origin. Cross-origin managed session creation is rejected; centralized/native deployments instead use a trusted backend-created opaque `sessionToken`.

## Internal routes are package infrastructure

Embedded mode registers same-application routes under `/jervis-captcha` by default:

- `POST /jervis-captcha/session/{action}`
- `POST /jervis-captcha/v1/challenges`
- `POST /jervis-captcha/v1/answers`
- `GET /jervis-captcha/assets/jervis-captcha.js`

These are **not a separate Jervis service**. They execute the Composer package inside the host Laravel request and use the host application's session/cache. They exist because a browser still needs to request a challenge and submit an answer to trusted server-side code.

The browser may provide an action name such as `signup`; it cannot provide a policy, expected answer, TTL, anti-AI flag or site secret. The backend middleware consumes the final proof for the same action.

## Policies and zero-config actions

`default_policy` is server-owned and applies to any syntactically valid action. This lets an application use `signup`, `login`, or `forgot-password` without duplicating policy configuration. `actions` can override the default for selected actions.

The engine applies global, per-action, global-per-binding and per-action-per-binding **session issuance** limits so inventing many action names does not bypass the issuance budget. Challenge refreshes and answer attempts are additionally bounded inside each verification session.

### Endpoint request budgets

The embedded Laravel routes also enforce action-independent request budgets before `issue()` and `solve()` execute. This prevents a caller from bypassing endpoint throttling by continuously creating different valid action names or sending large volumes of random but syntactically valid session tokens.

Defaults:

- `JERVIS_CAPTCHA_GLOBAL_REQUESTS_PER_MINUTE=5000`
- `JERVIS_CAPTCHA_CHALLENGES_PER_BINDING_PER_MINUTE=30`
- `JERVIS_CAPTCHA_ANSWERS_PER_BINDING_PER_MINUTE=60`

The per-binding key is derived from the trusted Laravel session binding, not a browser-provided identifier. A value outside `1..100000` is treated as invalid configuration and verification fails closed with `verification_unavailable`; `jervis-captcha:doctor` also reports the configuration as invalid.

These limits supplement normal application/WAF/rate-limiter controls; they do not replace them. Tune them for the expected concurrency of the protected flow rather than disabling them.

## State and atomicity

The default Laravel adapter uses the application's Cache Manager plus Laravel atomic locks. A proof is consumed under the same session-state lock, so two concurrent requests cannot both consume it. On multi-node deployments, all nodes must use the same shared lock-capable cache backend. Do not use independent local cache stores across nodes.

`php artisan jervis-captcha:doctor` checks PHP/GD/zlib, secure randomness, engine configuration, request budgets, the embedded digit-audio asset, browser assets and a real persistent cache lock before deployment. The Laravel `array` and `null` cache stores are rejected because CAPTCHA state has to survive across requests.

## Embedded audio

The PHP package now uses the **same generated English digit PCM samples** as the native Go engine. During the canonical build, `scripts/copy-assets.mjs` copies the generated native audio payload into `packages/php/resources/audio/digits.asset`. PHP decodes the local gzip/JSON payload and assembles an 8 kHz mono WAV on the server.

The browser receives only the final `data:audio/wav;base64,...` payload. It does not receive the answer text for browser TTS, and there is no CDN, speech API or runtime synthesizer dependency. Arabic-UI users may enter either Arabic or Western numerals because the verifier normalizes both, but the spoken audio is English digits in this release.

Audio is an accessibility/alternative modality, not an anti-AI guarantee; speech-to-text systems can solve audio challenges. `isAntiAI` may still apply the package's bounded proof-of-work layer, but product documentation must not claim that this makes audio AI-proof.

## Air-gapped package delivery

A successful embedded-package CI run builds two runtime archives from the exact browser artifact accepted by the PHP/Laravel tests:

- `jervis-captcha-php-<version>.zip` — Composer-compatible PHP package contents with the embedded browser and digit-audio assets.
- `jervis-captcha-frontend-<version>.tgz` — npm-compatible frontend package for SPA projects.
- `manifest.json` and `SHA256SUMS` — release metadata and integrity hashes.

The archives are generated by `python3 scripts/package-embedded.py`; that packaging step itself uses only local files and the Python standard library. Dependency resolution and the canonical TypeScript build happen on the connected build machine **before** the artifact is transferred into the isolated environment. The VCS Composer manifest intentionally has no hard-coded version; the archive builder validates the frontend/runtime version and injects that version only into the artifact's `composer.json` for offline Composer artifact repositories.

For an offline Laravel environment, place the verified PHP ZIP in a local Composer artifact repository directory, for example `packages/`, and configure the consuming application's Composer repository once:

```json
{
  "repositories": [
    { "type": "artifact", "url": "packages" }
  ]
}
```

Then install the transferred version without contacting Packagist:

```bash
composer require jervis/captcha:0.2.0-alpha.1 --no-interaction
php artisan jervis-captcha:install
php artisan jervis-captcha:doctor
```

Composer still needs all transitive Laravel dependencies to already exist in the customer's offline Composer cache/repository. The Jervis ZIP deliberately does not vendor the host application's Laravel dependencies.

For a separate SPA, transfer the frontend tarball into the project and install it from disk:

```bash
npm install ./packages/jervis-captcha-frontend-0.2.0-alpha.1.tgz
```

No CDN or remote runtime download is required after deployment. Verify `SHA256SUMS` before importing the archives into a controlled environment; signed release artifacts are a later production release gate.

## Embedded challenge scope

The embedded PHP engine implements text raster, slider, free drag, rotation, ordered click, English digit audio, math, logic and bounded proof-of-work.

`isAntiAI` remains an experimental hardening profile. It is not a claim that a challenge is AI-proof.
