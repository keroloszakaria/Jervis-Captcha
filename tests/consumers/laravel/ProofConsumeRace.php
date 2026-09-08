<?php
declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Jervis\Captcha\CaptchaException;
use Jervis\Captcha\Engine\EmbeddedEngine;

$root = rtrim((string) (getenv('JERVIS_CONSUMER_ROOT') ?: getcwd()), DIRECTORY_SEPARATOR);
if (!is_file($root . '/vendor/autoload.php') || !is_file($root . '/bootstrap/app.php')) {
    fwrite(STDERR, "JERVIS_CONSUMER_ROOT must point to an installed Laravel application\n");
    exit(65);
}

require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$store = $argv[1] ?? 'file';
$mode = $argv[2] ?? '';
$token = $argv[3] ?? '';
$binding = 'jervis-consumer-race-binding-0001';

if (!in_array($store, ['file', 'database', 'redis'], true)) {
    fwrite(STDERR, "Store must be file, database or redis\n");
    exit(64);
}

config()->set('cache.default', $store);
config()->set('jervis-captcha.store', $store);
config()->set('jervis-captcha.secret', str_repeat('s', 40));
config()->set('jervis-captcha.global_sessions_per_minute', 1000);
config()->set('jervis-captcha.default_policy', [
    'types' => ['pow'],
    'accessibilityTypes' => [],
    'isAntiAI' => false,
    'workBits' => 12,
    'minSolveMs' => 0,
    'sessionTtlSeconds' => 60,
    'challengeTtlSeconds' => 30,
    'proofTtlSeconds' => 30,
    'maxAttempts' => 3,
    'maxRefreshes' => 3,
    'sessionsPerMinute' => 100,
]);
config()->set('jervis-captcha.actions', []);

/** @var EmbeddedEngine $engine */
$engine = $app->make(EmbeddedEngine::class);

if ($mode === 'prepare') {
    $session = $engine->createSession('race', $binding);
    $challenge = $engine->issue($session['sessionToken'], $binding);
    $nonce = solveWork((string) $challenge['work']['seed'], (int) $challenge['work']['bits']);
    $proof = $engine->solve($session['sessionToken'], $binding, (string) $challenge['id'], [], $nonce);
    fwrite(STDOUT, $proof['token'] . PHP_EOL);
    exit(0);
}

if ($mode === 'consume') {
    if (!preg_match('/^[a-f0-9]{64}\.[A-Za-z0-9_-]{43}$/D', $token)) {
        fwrite(STDERR, "Invalid proof token\n");
        exit(64);
    }
    try {
        $engine->consume($token, 'race', $binding);
        fwrite(STDOUT, "consumed\n");
        exit(0);
    } catch (CaptchaException $e) {
        if ($e->errorCode === 'invalid_proof') {
            fwrite(STDOUT, "rejected\n");
            exit(2);
        }
        fwrite(STDERR, $e->errorCode . PHP_EOL);
        exit(3);
    } catch (Throwable $e) {
        fwrite(STDERR, $e::class . ': ' . $e->getMessage() . PHP_EOL);
        exit(4);
    }
}

fwrite(STDERR, "Usage: ProofConsumeRace.php <file|database|redis> <prepare|consume> [proof]\n");
exit(64);

function solveWork(string $seed, int $bits): string
{
    for ($nonce = 0; $nonce < 10_000_000; $nonce++) {
        $hash = hash('sha256', $seed . ':' . $nonce, true);
        $valid = true;
        for ($bit = 0; $bit < $bits; $bit++) {
            if ((ord($hash[intdiv($bit, 8)]) & (1 << (7 - ($bit % 8)))) !== 0) {
                $valid = false;
                break;
            }
        }
        if ($valid) return (string) $nonce;
    }
    throw new RuntimeException('Unable to solve bounded race-test proof of work');
}
