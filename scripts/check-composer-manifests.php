<?php
declare(strict_types=1);

function normalize(mixed $value): mixed
{
    if (!is_array($value)) return $value;
    if (!array_is_list($value)) ksort($value);
    foreach ($value as $key => $item) $value[$key] = normalize($item);
    return $value;
}

function jsonFile(string $path): array
{
    $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) throw new RuntimeException("Invalid JSON object: {$path}");
    return $decoded;
}

$repo = dirname(__DIR__);
$root = jsonFile($repo . '/composer.json');
$sub = jsonFile($repo . '/packages/php/composer.json');
foreach (['name', 'type', 'license', 'require', 'require-dev', 'extra'] as $key) {
    if (normalize($root[$key] ?? null) !== normalize($sub[$key] ?? null)) {
        fwrite(STDERR, "Composer manifests differ at {$key}\n");
        exit(1);
    }
}

$workspace = jsonFile($repo . '/package.json');
$frontend = jsonFile($repo . '/packages/frontend/package.json');
$embeddedSource = (string) file_get_contents($repo . '/packages/php/src/Engine/EmbeddedEngine.php');
$goSource = (string) file_get_contents($repo . '/internal/captcha/types.go');
if (!preg_match("/public\\s+const\\s+VERSION\\s*=\\s*'([^']+)'\\s*;/", $embeddedSource, $phpMatch)
    || !preg_match('/const\\s+Version\\s*=\\s*"([^"]+)"/', $goSource, $goMatch)) {
    fwrite(STDERR, "Could not resolve Jervis Captcha runtime versions\n");
    exit(1);
}
$versions = [
    'workspace' => $workspace['version'] ?? null,
    'frontend' => $frontend['version'] ?? null,
    'embeddedPhp' => $phpMatch[1],
    'nativeGo' => $goMatch[1],
];
$semver = '/^(0|[1-9]\\d*)\\.(0|[1-9]\\d*)\\.(0|[1-9]\\d*)(?:-[0-9A-Za-z.-]+)?(?:\\+[0-9A-Za-z.-]+)?$/D';
foreach ($versions as $label => $version) {
    if (!is_string($version) || preg_match($semver, $version) !== 1) {
        fwrite(STDERR, "Invalid Jervis Captcha version for {$label}\n");
        exit(1);
    }
}
if (count(array_unique(array_values($versions), SORT_STRING)) !== 1) {
    fwrite(STDERR, 'Jervis Captcha version drift: ' . json_encode($versions, JSON_UNESCAPED_SLASHES) . "\n");
    exit(1);
}

echo "composer manifests: synchronized\n";
echo 'jervis captcha version: ' . reset($versions) . "\n";
