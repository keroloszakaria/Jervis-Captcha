<?php
declare(strict_types=1);

require __DIR__ . '/TextSolverEvaluation.php';

$args = array_slice($argv, 1);
$outputPath = null;
$args = array_values(array_filter($args, static function (string $arg) use (&$outputPath): bool {
    if (str_starts_with($arg, '--output=')) {
        $outputPath = substr($arg, strlen('--output='));
        return false;
    }
    return true;
}));

if (count($args) < 3) {
    fwrite(STDERR, "usage: php CompareTextSolvers.php <manifest.json> <solver-a.json> <solver-b.json> [more.json...] [--output=report.json]" . PHP_EOL);
    exit(2);
}

try {
    $manifestPath = array_shift($args);
    $manifest = TextSolverEvaluation::loadJson($manifestPath);
    $documents = array_map(
        static fn (string $path): array => TextSolverEvaluation::loadJson($path),
        $args,
    );
    $result = TextSolverEvaluation::compare($manifest, $documents);
    $result['generatedAtUtc'] = gmdate(DATE_ATOM);
    $result['manifest'] = $manifestPath;
    $json = TextSolverEvaluation::encodeJson($result);

    if (is_string($outputPath) && $outputPath !== '') {
        $directory = dirname($outputPath);
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException("Unable to create output directory: {$directory}");
        }
        if (file_put_contents($outputPath, $json, LOCK_EX) === false) {
            throw new RuntimeException("Unable to write comparison report: {$outputPath}");
        }
        fwrite(STDOUT, "wrote comparison report to {$outputPath}" . PHP_EOL);
    } else {
        fwrite(STDOUT, $json);
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'comparison failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
