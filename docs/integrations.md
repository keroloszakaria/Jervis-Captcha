# Integration guide

The universal contract is a web component plus HTTP. There is no requirement for a particular application backend runtime. Framework-specific snippets below are **integration recipes**, not independently published framework packages or a claim that every historical framework version has been tested.

## Browser basics

Host the bundled `jervis-captcha.js` from your local engine/application. Use `<script type="module" src="/captcha/assets/jervis-captcha.js"></script>` or include the source module in your build. Assign `element.sessionToken = value`; this is deliberately a property, not a `session-token` attribute. Set `endpoint="/captcha/"` when using a reverse-proxy prefix.

Supported public properties: `endpoint`, `sessionToken`, `locale` (`en`/`ar`), `theme` (`light`/`dark`/`auto`), `presentation` (`inline`/`checkbox`/`invisible`), `disabled` and `name`. Methods: `start()`, `verify()`, `reset()`; getter: `token`. Events: `jervis-success` (proof), `jervis-error` (code), `jervis-reset`, `jervis-expired`, `jervis-renew`.

A checkbox starts verification and does not itself pass it. Invisible mode only hides automatic PoW, never a required human challenge or an error. The widget clears its form value on reset/expiry/disconnection. On renewed sessions, clear any token cached by the parent as well. For an external submit handler, never rely solely on the component's validity; the backend still consumes the proof.

## Vue and Nuxt

Configure the Vue template compiler's `isCustomElement` for `jervis-captcha`. Load/register the component on the client; in Nuxt use a `.client.ts` plugin or a client-only asset loader. Use a template ref and assign `ref.value.sessionToken` after your backend session response. Listen with `@jervis-success="onSuccess"`, `@jervis-reset="clearToken"` and `@jervis-expired="clearToken"`. Do not put site secrets in public runtime config.

## React and Next.js

Use a client component with an element ref. In an effect, load the local widget module, register native `addEventListener` handlers and assign the sessionToken property. Remove listeners in effect cleanup. This avoids assumptions about custom-event naming across React versions. Keep the private SDK and its secret exclusively in server routes; do not pass a configured server client into a client component.

## Angular

Load the locally served module once, add `CUSTOM_ELEMENTS_SCHEMA` to the component/module, and use `ViewChild` with `ElementRef<HTMLElement & { sessionToken: string }>` to assign the property. Listen to `(jervis-success)` and clear the form control on reset/expiry. A `ControlValueAccessor` package is not yet included; explicit event-to-form wiring is required. Instantiate only in the browser during SSR/hydration.

## HTML, Blade, Razor, Django, Svelte and other web frontends

The same local module, property assignment and native events work without a UI-library dependency. Vanilla HTML is the primary browser acceptance fixture. SSR import safety and browser capability must be tested in the actual consuming application. Native Flutter/React Native screens need a separately validated WebView/native presentation; the web widget is not a native control.

## Backend clients

- Node/TypeScript: `sdk/node/client.ts`, class `JervisCaptchaServer`. Methods `createSession({action,binding,type?})` and `verify({token,action,binding})`. Transport/invalid responses throw `VerificationError`; no consumption retry.
- PHP: `sdk/php/JervisCaptcha.php`, class `Jervis\Captcha\JervisCaptcha`. Use a Composer path repository or include it locally; requires PHP 8.2 and cURL. Methods `createSession` and `verify`. Reject on false **or exception**.
- Python: `sdk/python/jervis_captcha.py`. Standard-library synchronous client. Reject on false or `VerificationError`; internal proxy inheritance is disabled.
- .NET: `sdk/dotnet/Jervis.Captcha.csproj`, target .NET 8. Owns an HttpClient that refuses redirects. Use trusted TLS and dispose with the application lifecycle. Reject on false or any transport exception.

Other languages use the same OpenAPI contract directly. No SDK is required to start, and the engine is not reimplemented in every language.

### Laravel pattern

Register the PHP client as a server-side container singleton using secrets from protected environment/configuration. Protect the session route and business form with Laravel's normal session/CSRF middleware. Derive binding from the server session (or an HMAC of it). The session endpoint returns only sessionToken and its relative lifetime. The business controller calls `verify($token, 'signup', $binding)` before any protected side effect and returns validation failure on false/exception. Do not create a public route that lets the browser choose arbitrary actions, bindings or policies.

## Styles and CSP

Customize `--jc-accent`, `--jc-bg`, `--jc-fg`, `--jc-muted`, `--jc-border`, plus the exposed `container` and `verify-button` CSS parts. Shadow DOM isolates widget styles. CSS/system fonts are local; no font service is used.

Merge the widget's requirements with the host CSP: local scripts, local API connections, PNG `data:` images, WAV `data:` media, and the locally generated blob worker. Current dynamic positioning/inline styles require an appropriate style policy. Do not weaken the host script policy to `unsafe-eval` or `unsafe-inline`; neither is required by the widget.
