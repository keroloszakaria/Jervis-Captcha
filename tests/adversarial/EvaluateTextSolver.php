<?php
declare(strict_types=1);

$manifestPath = $argv[1] ?? '.adversarial-text-corpus/manifest.json';
$predictionsPath = $argv[2] ?? '.adversarial-text-corpus/windows-ocr-predictions.json';
$manifest = loadJson($manifestPath);
$predictions = loadJson($predictionsPath);

$expected = [];
foreach (($manifest['records'] ?? []) as $record) {
    $expected[(string) $record['path']] = $record;
}

$groups = [];
foreach (($predictions['predictions'] ?? []) as $prediction) {
    $path = (string) ($prediction['path'] ?? '');
    if (!isset($expected[$path])) throw new RuntimeException("Prediction path is not in manifest: {$path}");
    $record = $expected[$path];
    $want = normalize((string) $record['answer']);
    $got = normalize((string) ($prediction['prediction'] ?? ''));
    $key = $record['profile'] . ':' . $record['variant'];
    $groups[$key] ??= [
        'profile' => $record['profile'], 'variant' => $record['variant'], 'count' => 0,
        'exact' => 0, 'empty' => 0, 'charAccuracyTotal' => 0.0, 'durationMsTotal' => 0.0,
    ];
    $groups[$key]['count']++;
    $groups[$key]['exact'] += hash_equals($want, $got) ? 1 : 0;
    $groups[$key]['empty'] += $got === '' ? 1 : 0;
    $distance = levenshtein($want, $got);
    $denominator = max(1, strlen($want), strlen($got));
    $groups[$key]['charAccuracyTotal'] += max(0.0, 1.0 - ($distance / $denominator));
    $groups[$key]['durationMsTotal'] += (float) ($prediction['durationMs'] ?? 0.0);
}

$summary = [];
foreach ($groups as $group) {
    $count = max(1, (int) $group['count']);
    $summary[] = [
        'profile' => $group['profile'],
        'variant' => $group['variant'],
        'count' => $count,
        'exactMatches' => $group['exact'],
        'exactRate' => round($group['exact'] / $count, 4),
        'emptyRate' => round($group['empty'] / $count, 4),
        'meanCharAccuracy' => round($group['charAccuracyTotal'] / $count, 4),
        'meanSolverMs' => round($group['durationMsTotal'] / $count, 3),
    ];
}
usort($summary, static fn (array $a, array $b): int => [$a['profile'], $a['variant']] <=> [$b['profile'], $b['variant']]);

$profileSummary = [];
foreach ($summary as $row) {
    $profile = (string) $row['profile'];
    $profileSummary[$profile] ??= [
        'profile' => $profile, 'attempts' => 0, 'exactMatches' => 0,
        'worstVariant' => '', 'worstVariantExactRate' => -1.0,
        'highestVariantCharAccuracy' => 0.0,
    ];
    $profileSummary[$profile]['attempts'] += (int) $row['count'];
    $profileSummary[$profile]['exactMatches'] += (int) $row['exactMatches'];
    if ((float) $row['exactRate'] > $profileSummary[$profile]['worstVariantExactRate']) {
        $profileSummary[$profile]['worstVariantExactRate'] = (float) $row['exactRate'];
        $profileSummary[$profile]['worstVariant'] = (string) $row['variant'];
    }
    $profileSummary[$profile]['highestVariantCharAccuracy'] = max(
        $profileSummary[$profile]['highestVariantCharAccuracy'],
        (float) $row['meanCharAccuracy'],
    );
}
foreach ($profileSummary as &$profile) {
    $profile['exactAttemptRate'] = round($profile['exactMatches'] / max(1, $profile['attempts']), 4);
}
unset($profile);
$profileSummary = array_values($profileSummary);
usort($profileSummary, static fn (array $a, array $b): int => $a['profile'] <=> $b['profile']);

$result = [
    'solver' => (string) ($predictions['solver'] ?? 'unknown'),
    'language' => (string) ($predictions['language'] ?? ''),
    'records' => count($predictions['predictions'] ?? []),
    'profileSummary' => $profileSummary,
    'summary' => $summary,
];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;

function normalize(string $value): string
{
    return strtoupper((string) preg_replace('/[^A-Z0-9]/i', '', $value));
}

function loadJson(string $path): array
{
    $raw = (string) file_get_contents($path);
    if (str_starts_with($raw, "\xEF\xBB\xBF")) $raw = substr($raw, 3);
    $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) throw new RuntimeException("Expected JSON object in {$path}");
    return $decoded;
}
