<?php
declare(strict_types=1);

require __DIR__ . '/TextSolverEvaluation.php';

$manifestPath = $argv[1] ?? '.adversarial-text-corpus/manifest.json';
$predictionsPath = $argv[2] ?? '.adversarial-text-corpus/windows-ocr-predictions.json';
$outputPath = $argv[3] ?? null;

try {
    $result = TextSolverEvaluation::evaluate(
        TextSolverEvaluation::loadJson($manifestPath),
        TextSolverEvaluation::loadJson($predictionsPath),
    );
    $json = TextSolverEvaluation::encodeJson($result);
    if (is_string($outputPath) && $outputPath !== '') {
        $directory = dirname($outputPath);
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException("Unable to create output directory: {$directory}");
        }
        if (file_put_contents($outputPath, $json, LOCK_EX) === false) {
            throw new RuntimeException("Unable to write evaluation: {$outputPath}");
        }
        fwrite(STDOUT, "wrote evaluation to {$outputPath}" . PHP_EOL);
    } else {
        fwrite(STDOUT, $json);
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'evaluation failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
