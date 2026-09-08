# @jervis/captcha

Framework-neutral browser package for Jervis Captcha. It contains the widget and client-side interaction logic; verification authority remains in the backend package/engine.

```ts
import '@jervis/captcha';
```

For a Laravel embedded backend on the same origin:

```html
<jervis-captcha action="signup" endpoint="/jervis-captcha/"></jervis-captcha>
```

The widget creates its package-managed verification session, issues challenges, performs bounded proof-of-work when required, submits answers and exposes only the final one-use proof as its form value. Policy, answers and secrets never come from the browser.
