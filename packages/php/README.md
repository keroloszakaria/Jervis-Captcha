# jervis/captcha

Embedded Laravel integration for Jervis Captcha. It runs the verification engine inside the host PHP process; no Jervis daemon, separate port or remote CAPTCHA API is required.

```bash
composer require jervis/captcha
php artisan jervis-captcha:install
php artisan jervis-captcha:doctor
# Optional dedicated base secret; APP_KEY remains the zero-config fallback.
php artisan jervis-captcha:key
```

```blade
<x-jervis-captcha action="signup" />
```

```php
Route::post('/signup', SignupController::class)->middleware('jervis.captcha:signup');
```

The component and middleware must use the same application-owned action. Challenge policy, answers, session binding and proof consumption stay inside the package. See `docs/php-embedded.md` in the source repository for security and deployment details.
