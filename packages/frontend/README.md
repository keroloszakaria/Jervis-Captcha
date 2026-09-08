# @jervis/captcha

Framework-neutral browser package for Jervis Captcha. It contains the widget and client-side interaction logic; verification authority remains in the backend package/engine.

```ts
import '@jervis/captcha';
```

For a Laravel embedded backend on the same origin, the default integration is only:

```html
<jervis-captcha action="signup"></jervis-captcha>
```

When `action` is present and no explicit server-created `sessionToken` is supplied, the package uses the Laravel embedded route prefix `/jervis-captcha/` automatically. Applications mounted under a custom base path or custom package prefix can still set `endpoint` explicitly.

The widget creates its package-managed verification session, issues challenges, performs bounded proof-of-work when required, submits answers, recovers expired/locked managed sessions, and exposes only the final one-use proof as its form value. Policy, answers and secrets never come from the browser.

If the backend policy exposes an accessibility alternative, the widget shows **Use an accessible challenge** (Arabic: **استخدم تحديًا ميسّرًا**). The browser requests only `variant: "accessible"`; it never chooses a concrete CAPTCHA type. Laravel selects the challenge from the server-owned `accessibilityTypes` policy. The default embedded policy uses the locally packaged English digit audio challenge.

Cross-origin managed session creation is intentionally rejected. A centralized/native deployment must have the trusted host backend create the session and pass the opaque `sessionToken` to the widget instead.
