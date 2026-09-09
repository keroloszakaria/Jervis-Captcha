<?php
declare(strict_types=1);

$root = rtrim((string) (getenv('JERVIS_CONSUMER_ROOT') ?: ''), DIRECTORY_SEPARATOR);
$routesPath = $root . '/routes/web.php';
if (!is_file($root . '/vendor/autoload.php') || !is_file($routesPath)) {
    fwrite(STDERR, "JERVIS_CONSUMER_ROOT must be an installed Laravel application\n");
    exit(65);
}

$routes = file_get_contents($routesPath);
if (!is_string($routes)) {
    fwrite(STDERR, "Unable to read routes/web.php\n");
    exit(66);
}

// The block is test-only and appended after Laravel boot. It overrides only the
// policy used by this acceptance run; package config/env values such as secrets
// and stores remain exactly as a real consumer would resolve them.
$routes = preg_replace(
    '/\n?\/\/ JERVIS_CAPTCHA_REAL_HTTP_ACCEPTANCE(?:_BEGIN)?[\s\S]*$/',
    '',
    $routes,
) ?? $routes;

$block = <<<'PHP'

// JERVIS_CAPTCHA_REAL_HTTP_ACCEPTANCE_BEGIN
config()->set('jervis-captcha.default_policy', [
    'types' => ['pow'], 'accessibilityTypes' => ['audio'], 'isAntiAI' => false,
    'workBits' => 12, 'minSolveMs' => 0, 'sessionTtlSeconds' => 120,
    'challengeTtlSeconds' => 60, 'proofTtlSeconds' => 30,
    'maxAttempts' => 3, 'maxRefreshes' => 3, 'sessionsPerMinute' => 100,
]);
config()->set('jervis-captcha.request_limits', [
    'global_per_minute' => 1000, 'session_per_binding_per_minute' => 100,
    'challenge_per_binding_per_minute' => 100, 'answer_per_binding_per_minute' => 100,
]);
\Illuminate\Support\Facades\Route::get('/__jervis/csrf', fn () => response()->json(['token' => csrf_token()]));
\Illuminate\Support\Facades\Route::post('/__jervis/protected', fn () => response()->json(['accepted' => true]))
    ->middleware('jervis.captcha:signup');
\Illuminate\Support\Facades\Route::get('/__jervis/browser', function () {
    return \Illuminate\Support\Facades\Blade::render(<<<'BLADE'
<!doctype html><html><head><meta name="csrf-token" content="{{ csrf_token() }}"></head><body>
<form id="protected-form" method="POST" action="/__jervis/browser-submit">
@csrf
<x-jervis-captcha action="signup" presentation="invisible" />
<button id="submit" type="submit">Submit protected action</button>
</form>
</body></html>
BLADE);
});
\Illuminate\Support\Facades\Route::post('/__jervis/browser-submit', fn () => response('<p id="result">accepted</p>'))
    ->middleware('jervis.captcha:signup');
\Illuminate\Support\Facades\Route::get('/__jervis/spa', function () {
    return \Illuminate\Support\Facades\Blade::render(<<<'BLADE'
<!doctype html><html><head><meta name="csrf-token" content="{{ csrf_token() }}">
<script type="module" src="/__jervis/frontend/jervis-captcha.js"></script></head><body>
<form id="protected-form" method="POST" action="/__jervis/spa-submit">
@csrf
<jervis-captcha action="signup" presentation="invisible"></jervis-captcha>
<button id="submit" type="submit">Submit protected action</button>
</form>
</body></html>
BLADE);
});
\Illuminate\Support\Facades\Route::post('/__jervis/spa-submit', fn () => response('<p id="result">accepted</p>'))
    ->middleware('jervis.captcha:signup');
// JERVIS_CAPTCHA_REAL_HTTP_ACCEPTANCE_END
PHP;

file_put_contents($routesPath, rtrim($routes) . $block . PHP_EOL);
fwrite(STDOUT, "prepared HTTP consumer fixture\n");
