<?php
declare(strict_types=1);

use Jervis\Captcha\Engine\ChallengeGenerator;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$count = max(5, min(100, (int) ($argv[1] ?? 20)));
$outDir = $argv[2] ?? dirname(__DIR__, 2) . '/.adversarial-text-corpus';
if (!is_dir($outDir) && !mkdir($outDir, 0777, true) && !is_dir($outDir)) {
    throw new RuntimeException("Unable to create corpus directory: {$outDir}");
}

$generator = new ChallengeGenerator();
$corpusId = bin2hex(random_bytes(16));
$records = [];
foreach (['standard' => false, 'antiAi' => true] as $profile => $antiAi) {
    for ($i = 0; $i < $count; $i++) {
        [$challenge, $expected] = $generator->generate('text', $antiAi);
        $answer = (string) ($expected['text'] ?? '');
        $raw = decodeDataUrl((string) ($challenge['image'] ?? ''));
        $base = sprintf('%s-%03d', $profile, $i + 1);
        $variants = writeVariants($raw, $outDir, $base);
        foreach ($variants as $variant => $path) {
            $records[] = compact('profile', 'variant', 'answer', 'path');
        }
    }
}

$generatedAtUtc = gmdate(DATE_ATOM);
$metadata = [
    'corpusId' => $corpusId,
    'generatedAtUtc' => $generatedAtUtc,
    'samplesPerProfile' => $count,
    'profiles' => ['standard', 'antiAi'],
    'variants' => ['original', 'resize2x', 'jpeg65', 'grayscale'],
];
writeJson($outDir . '/manifest.json', $metadata + ['records' => $records]);
$solverRecords = array_map(
    static fn (array $record): array => [
        'profile' => $record['profile'],
        'variant' => $record['variant'],
        'path' => $record['path'],
    ],
    $records,
);
writeJson($outDir . '/solver-manifest.json', $metadata + ['records' => $solverRecords]);
fwrite(STDOUT, sprintf("generated %d images across %d records in %s (corpus %s)\n", $count * 2, count($records), $outDir, $corpusId));

function decodeDataUrl(string $value): string
{
    if (!preg_match('#^data:image/png;base64,(.+)$#D', $value, $match)) {
        throw new RuntimeException('Unexpected CAPTCHA image data URL');
    }
    $raw = base64_decode($match[1], true);
    if ($raw === false || $raw === '') throw new RuntimeException('Unable to decode CAPTCHA PNG');
    return $raw;
}

/** @return array<string,string> */
function writeVariants(string $raw, string $outDir, string $base): array
{
    $source = imagecreatefromstring($raw);
    if (!$source instanceof GdImage) throw new RuntimeException('Unable to decode generated image');
    $result = [];
    $result['original'] = writePng($source, $outDir, $base . '-original.png');

    $width = imagesx($source);
    $height = imagesy($source);
    $resize = imagecreatetruecolor($width * 2, $height * 2);
    if (!$resize instanceof GdImage) throw new RuntimeException('Unable to allocate resize canvas');
    imagecopyresampled($resize, $source, 0, 0, 0, 0, $width * 2, $height * 2, $width, $height);
    $result['resize2x'] = writePng($resize, $outDir, $base . '-resize2x.png');
    imagedestroy($resize);

    $jpegPath = $outDir . '/' . $base . '-jpeg65.jpg';
    if (!imagejpeg($source, $jpegPath, 65)) throw new RuntimeException('Unable to write JPEG variant');
    $result['jpeg65'] = $jpegPath;

    $gray = imagecreatefromstring($raw);
    if (!$gray instanceof GdImage) throw new RuntimeException('Unable to allocate grayscale image');
    imagefilter($gray, IMG_FILTER_GRAYSCALE);
    imagefilter($gray, IMG_FILTER_CONTRAST, -25);
    $result['grayscale'] = writePng($gray, $outDir, $base . '-grayscale.png');
    imagedestroy($gray);
    imagedestroy($source);
    return $result;
}

function writePng(GdImage $image, string $outDir, string $name): string
{
    $path = $outDir . '/' . $name;
    if (!imagepng($image, $path, 7)) throw new RuntimeException("Unable to write {$name}");
    return $path;
}

function writeJson(string $path, array $value): void
{
    $json = json_encode(
        $value,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
    );
    if (file_put_contents($path, $json, LOCK_EX) === false) {
        throw new RuntimeException("Unable to write corpus manifest: {$path}");
    }
}
