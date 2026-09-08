# Embedded PHP / Laravel package

Jervis Captcha can run **inside the Laravel PHP process**. This mode does not start the Go executable, does not call a second verification service and does not need another port, container or daemon. The package owns the challenge generation, state machine, verification, proof consumption, Laravel routes, cache locks and browser asset.

## Intended Laravel usage

The repository root also contains the Composer package manifest, so a tagged/released repository can be consumed as `jervis/captcha`; `packages/php/composer.json` mirrors the PHP subtree for a future split package repository.

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
<jervis-captcha action="signup" endpoint="/jervis-captcha/"></jervis-captcha>
```

Vue, Nuxt, React, Next and Angular can use the same custom element. The browser sends same-origin session credentials only; it never receives the application secret or expected CAPTCHA answer. For Laravel CSRF, the widget uses its explicit package-provided token in Blade, a standard `meta[name=csrf-token]`, or Laravel's `XSRF-TOKEN` cookie. CSRF material is never forwarded cross-origin.

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

The engine applies global, per-action, global-per-binding and per-action-per-binding issuance limits so inventing many action names does not bypass the issuance budget.

## State and atomicity

The default Laravel adapter uses the application's Cache Manager plus Laravel atomic locks. A proof is consumed under the same session-state lock, so two concurrent requests cannot both consume it. On multi-node deployments, all nodes must use the same shared lock-capable cache backend. Do not use independent local cache stores across nodes.

`php artisan jervis-captcha:doctor` checks PHP/GD, secure randomness, browser assets and a real cache lock before deployment.

## Embedded challenge scope

The initial PHP engine implements text raster, slider, free drag, rotation, ordered click, math, logic and bounded proof-of-work. Audio remains available in the native Go engine but is not claimed for the embedded PHP engine until a locally packaged PHP audio implementation is added.

`isAntiAI` remains an experimental hardening profile. It is not a claim that a challenge is AI-proof.
