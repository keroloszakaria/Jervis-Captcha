# Integration guide

The universal contract is a web component plus HTTP. There is no requirement for a particular application backend runtime. Framework-specific snippets below are **integration recipes**, not independently published framework packages or a claim that every historical framework version has been tested.

## Browser basics

The browser package supports two server contracts without changing its challenge UI:

- **Embedded Laravel mode:** set `action` and `endpoint`. The widget creates its own package-managed session; the application does not write session orchestration code.
- **Native/universal mode:** the host backend creates a session and assigns `element.sessionToken`; this remains available for non-PHP and centralized deployments.

Supported public properties: `endpoint`, `action`, `csrfToken`, `sessionToken`, `locale` (`en`/`ar`), `theme` (`light`/`dark`/`auto`), `presentation` (`inline`/`checkbox`/`invisible`), `disabled` and `name`. Methods: `start()`, `verify()`, `reset()`, `renew()`; getter: `token`. Events: `jervis-success` (proof), `jervis-error` (code), `jervis-reset`, `jervis-expired`, `jervis-renew`.

For embedded Laravel on the same origin:

```ts
import '@jervis/captcha';
```

```html
<jervis-captcha action="signup" endpoint="/jervis-captcha/"></jervis-captcha>
```

A checkbox starts verification and does not itself pass it. Invisible mode only hides automatic PoW, never a required human challenge or an error. The backend still atomically consumes the final proof.

## Vue and Nuxt

Configure Vue's `isCustomElement` for `jervis-captcha` and import `@jervis/captcha` in a client-only plugin when using SSR. With embedded Laravel, render the custom element with `action="signup"` and the package endpoint; no Vue composable or application-owned session request is required. Clear any application-cached proof on reset/expiry if you mirror the component value externally.

## React and Next.js

Use a client component that imports `@jervis/captcha` and renders the custom element. With embedded Laravel, `action` + `endpoint` are sufficient. Native-engine deployments may still set the `sessionToken` property from a trusted server route. Do not put backend secrets in React props or public configuration.

## Angular

Import/register the package once in the browser and add `CUSTOM_ELEMENTS_SCHEMA`. Embedded Laravel needs only `action` + `endpoint`; the Web Component owns session bootstrap and challenge orchestration. A dedicated `ControlValueAccessor` wrapper is not yet published; the element itself remains form-associated in browsers that support `ElementInternals`.

## HTML, Blade, Razor, Django, Svelte and other web frontends

The same Web Component works without a UI-library dependency. In Laravel Blade prefer `<x-jervis-captcha action="signup" />`, which injects the local asset, relative same-origin endpoint and CSRF token. Other backends can import `@jervis/captcha` and use the native-engine session-token contract. Native Flutter/React Native screens still need a separately validated WebView/native presentation.

## Backend clients

- Node/TypeScript: `sdk/node/client.ts`, class `JervisCaptchaServer`. Methods `createSession({action,binding,type?})` and `verify({token,action,binding})`. Transport/invalid responses throw `VerificationError`; no consumption retry.
- PHP: `sdk/php/JervisCaptcha.php`, class `Jervis\Captcha\JervisCaptcha`. Use a Composer path repository or include it locally; requires PHP 8.2 and cURL. Methods `createSession` and `verify`. Reject on false **or exception**.
- Python: `sdk/python/jervis_captcha.py`. Standard-library synchronous client. Reject on false or `VerificationError`; internal proxy inheritance is disabled.
- .NET: `sdk/dotnet/Jervis.Captcha.csproj`, target .NET 8. Owns an HttpClient that refuses redirects. Use trusted TLS and dispose with the application lifecycle. Reject on false or any transport exception.

Other languages use the same OpenAPI contract directly. No SDK is required to start, and the engine is not reimplemented in every language.

### Laravel embedded package

Prefer `jervis/captcha` instead of the legacy HTTP client when the engine should live inside Laravel. The Service Provider registers package-owned challenge routes, a lock-backed state store, Blade component and `jervis.captcha:<action>` middleware. The application does not call `verify()` manually. Browser-provided action names can select only a server-owned default/override policy; the browser cannot choose binding, TTL, answer, `isAntiAI`, or any secret.

The older `sdk/php/JervisCaptcha.php` remains for PHP applications intentionally using the optional native Go service.

## Styles and CSP

Customize `--jc-accent`, `--jc-bg`, `--jc-fg`, `--jc-muted`, `--jc-border`, plus the exposed `container` and `verify-button` CSS parts. Shadow DOM isolates widget styles. CSS/system fonts are local; no font service is used.

Merge the widget's requirements with the host CSP: local scripts, local API connections, PNG `data:` images, WAV `data:` media, and the locally generated blob worker. Current dynamic positioning/inline styles require an appropriate style policy. Do not weaken the host script policy to `unsafe-eval` or `unsafe-inline`; neither is required by the widget.

## Embedded Laravel mode

For Laravel applications that should not run a separate Jervis service, use the Composer package in `packages/php`. The target public API is one Blade component plus one middleware declaration:

```blade
<x-jervis-captcha action="signup" />
```

```php
Route::post('/signup', SignupController::class)->middleware('jervis.captcha:signup');
```

The package registers and owns the same-application challenge routes and derives binding from the Laravel session. The browser widget can also be consumed from `@jervis/captcha`; it automatically creates a package-managed session when the `action` property is present. See [Embedded PHP / Laravel](./php-embedded.md).
