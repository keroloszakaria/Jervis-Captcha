<?php
declare(strict_types=1);

function normalize(mixed $value): mixed
{
    if (!is_array($value)) return $value;
    if (!array_is_list($value)) ksort($value);
    foreach ($value as $key => $item) $value[$key] = normalize($item);
    return $value;
}

$root = json_decode((string) file_get_contents(__DIR__ . '/../composer.json'), true, flags: JSON_THROW_ON_ERROR);
$sub = json_decode((string) file_get_contents(__DIR__ . '/../packages/php/composer.json'), true, flags: JSON_THROW_ON_ERROR);
foreach (['name', 'type', 'license', 'require', 'require-dev', 'extra'] as $key) {
    if (normalize($root[$key] ?? null) !== normalize($sub[$key] ?? null)) {
        fwrite(STDERR, "Composer manifests differ at {$key}\n");
        exit(1);
    }
}
echo "composer manifests: synchronized\n";
