<?php
declare(strict_types=1);

use Jervis\Captcha\CaptchaException;
use Jervis\Captcha\Engine\ChallengeGenerator;
use Jervis\Captcha\Engine\EmbeddedEngine;
use Jervis\Captcha\Engine\MemoryStore;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$iterations = max(5, min(100, (int) ($argv[1] ?? 20)));
$generator = new ChallengeGenerator();
$types = ['text', 'slider', 'drag', 'rotate', 'click', 'audio', 'math', 'logic', 'pow'];
$results = [];

foreach ($types as $type) {
    $samples = [];
    $sizes = [];
    for ($i = 0; $i < $iterations; $i++) {
        $started = hrtime(true);
        [$challenge] = $generator->generate($type, $type === 'text');
        $samples[] = (hrtime(true) - $started) / 1_000_000;
        $sizes[] = strlen(json_encode($challenge, JSON_THROW_ON_ERROR));
    }
    $results[$type] = summarize($samples, $sizes);
}
$policy = [
    'types' => ['pow'], 'accessibilityTypes' => [], 'isAntiAI' => false,
    'workBits' => 12, 'minSolveMs' => 0, 'sessionTtlSeconds' => 60,
    'challengeTtlSeconds' => 30, 'proofTtlSeconds' => 30,
    'maxAttempts' => 3, 'maxRefreshes' => 3, 'sessionsPerMinute' => 100,
];
$engine = new EmbeddedEngine(new MemoryStore(), str_repeat('s', 40), [], $policy, null, 1000);
$sessionSamples = [];
for ($i = 0; $i < $iterations; $i++) {
    $started = hrtime(true);
    $engine->createSession('smoke', sprintf('benchmark-binding-%04d-abcdef', $i));
    $sessionSamples[] = (hrtime(true) - $started) / 1_000_000;
}
$results['sessionCreate'] = summarize($sessionSamples, []);

$invalidSamples = [];
for ($i = 0; $i < $iterations * 10; $i++) {
    $started = hrtime(true);
    try {
        $engine->consume('invalid-proof', 'smoke', 'benchmark-binding-invalid-abcdef');
    } catch (CaptchaException $e) {
        if ($e->errorCode !== 'invalid_proof') throw $e;
    }
    $invalidSamples[] = (hrtime(true) - $started) / 1_000_000;
}
$results['invalidProofReject'] = summarize($invalidSamples, []);
$output = [
    'iterations' => $iterations,
    'php' => PHP_VERSION,
    'memoryLimit' => ini_get('memory_limit'),
    'peakMemoryBytes' => memory_get_peak_usage(true),
    'results' => $results,
];
echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;

/** @param list<float> $samples @param list<int> $sizes */
function summarize(array $samples, array $sizes): array
{
    sort($samples, SORT_NUMERIC);
    $count = count($samples);
    $p50 = $samples[(int) floor(($count - 1) * 0.50)];
    $p95 = $samples[(int) floor(($count - 1) * 0.95)];
    $result = [
        'meanMs' => round(array_sum($samples) / $count, 3),
        'p50Ms' => round($p50, 3),
        'p95Ms' => round($p95, 3),
        'maxMs' => round(max($samples), 3),
    ];
    if ($sizes !== []) {
        $result['meanPayloadBytes'] = (int) round(array_sum($sizes) / count($sizes));
        $result['maxPayloadBytes'] = max($sizes);
    }
    return $result;
}
