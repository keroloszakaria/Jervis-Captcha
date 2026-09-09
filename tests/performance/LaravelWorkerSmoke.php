<?php
declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Jervis\Captcha\Engine\EmbeddedEngine;

$root = rtrim((string) (getenv('JERVIS_CONSUMER_ROOT') ?: ''), DIRECTORY_SEPARATOR);
if (!is_file($root . '/vendor/autoload.php') || !is_file($root . '/bootstrap/app.php')) {
    fwrite(STDERR, "JERVIS_CONSUMER_ROOT must point to an installed Laravel app\n");
    exit(65);
}
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$type = (string) ($argv[1] ?? 'click');
$iterations = max(1, min(50, (int) ($argv[2] ?? 20)));
$worker = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($argv[3] ?? 'w')) ?: 'w';
$store = (string) ($argv[4] ?? 'file');
if (!in_array($store, ['file', 'database', 'redis'], true)) { fwrite(STDERR, "store must be file, database or redis\n"); exit(64); }

config()->set('cache.default', $store);
config()->set('jervis-captcha.store', $store);
if ($store === 'redis') {
    $client = getenv('JERVIS_SMOKE_REDIS_CLIENT');
    if (is_string($client) && $client !== '') config()->set('database.redis.client', $client);
    config()->set('database.redis.default.host', getenv('REDIS_HOST') ?: '127.0.0.1');
    config()->set('database.redis.default.port', (int) (getenv('REDIS_PORT') ?: 6379));
}
config()->set('jervis-captcha.secret', str_repeat('s', 40));
config()->set('jervis-captcha.global_sessions_per_minute', 1000);
config()->set('jervis-captcha.actions', []);
config()->set('jervis-captcha.default_policy', [
    'types' => [$type], 'accessibilityTypes' => [], 'isAntiAI' => $type === 'text',
    'workBits' => 12, 'minSolveMs' => 0, 'sessionTtlSeconds' => 120,
    'challengeTtlSeconds' => 60, 'proofTtlSeconds' => 30,
    'maxAttempts' => 3, 'maxRefreshes' => 3, 'sessionsPerMinute' => 500,
]);

/** @var EmbeddedEngine $engine */
$engine = $app->make(EmbeddedEngine::class);
$started = hrtime(true);
$payloadBytes = 0;
for ($i = 0; $i < $iterations; $i++) {
    $binding = sprintf('load-worker-%s-binding-%04d-abcdef', $worker, $i);
    $session = $engine->createSession('load', $binding);
    $challenge = $engine->issue($session['sessionToken'], $binding);
    $payloadBytes += strlen(json_encode($challenge, JSON_THROW_ON_ERROR));
}

$elapsedMs = (hrtime(true) - $started) / 1_000_000;

echo json_encode([
    'worker' => $worker, 'store' => $store, 'type' => $type, 'iterations' => $iterations,
    'elapsedMs' => round($elapsedMs, 3),
    'meanMsPerSessionIssue' => round($elapsedMs / $iterations, 3),
    'payloadBytes' => $payloadBytes,
    'peakMemoryBytes' => memory_get_peak_usage(true),
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
