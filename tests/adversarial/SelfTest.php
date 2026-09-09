<?php
declare(strict_types=1);

require __DIR__ . '/TextSolverEvaluation.php';

$root = sys_get_temp_dir() . '/jervis-captcha-adversarial-' . bin2hex(random_bytes(8));
if (!mkdir($root, 0777, true) && !is_dir($root)) throw new RuntimeException("Unable to create {$root}");

try {
    $records = [];
    foreach ([
        ['standard', 'original', 'AB12'],
        ['standard', 'grayscale', 'CD34'],
        ['antiAi', 'original', 'EF56'],
        ['antiAi', 'grayscale', 'GH78'],
    ] as [$profile, $variant, $answer]) {
        $path = $root . DIRECTORY_SEPARATOR . $answer . '.png';
        file_put_contents($path, 'fixture');
        $records[] = compact('profile', 'variant', 'answer', 'path');
    }

    $corpusId = bin2hex(random_bytes(16));
    $manifestPath = $root . '/manifest.json';
    writeJson($manifestPath, ['corpusId' => $corpusId, 'records' => $records]);
    $solverManifestPath = $root . '/solver-manifest.json';
    $solverRecords = array_map(
        static fn (array $record): array => [
            'profile' => $record['profile'],
            'variant' => $record['variant'],
            'path' => $record['path'],
        ],
        $records,
    );
    writeJson($solverManifestPath, ['corpusId' => $corpusId, 'records' => $solverRecords]);
    $perfectConfigPath = $root . '/perfect.json';
    writeJson($perfectConfigPath, [
        'solver' => 'fixture-perfect',
        'language' => 'en',
        'timeoutMs' => 3000,
        'command' => [PHP_BINARY, '-n', '-r', 'echo pathinfo($argv[1], PATHINFO_FILENAME);', '{input}'],
    ]);

    $weakConfigPath = $root . '/weak.json';
    writeJson($weakConfigPath, [
        'solver' => 'fixture-weak',
        'language' => 'en',
        'timeoutMs' => 3000,
        'command' => [PHP_BINARY, '-n', '-r', 'echo "ZZZZ";', '{input}'],
    ]);

    $perfectPredictionsPath = $root . '/perfect-predictions.json';
    $weakPredictionsPath = $root . '/weak-predictions.json';
    runCli([PHP_BINARY, __DIR__ . '/CommandTextSolver.php', $solverManifestPath, $perfectConfigPath, $perfectPredictionsPath]);
    runCli([PHP_BINARY, __DIR__ . '/CommandTextSolver.php', $solverManifestPath, $weakConfigPath, $weakPredictionsPath]);

    $manifest = TextSolverEvaluation::loadJson($manifestPath);
    $perfect = TextSolverEvaluation::loadJson($perfectPredictionsPath);
    $weak = TextSolverEvaluation::loadJson($weakPredictionsPath);
    assertSameValue($corpusId, $perfect['corpusId'], 'command adapter corpus binding');
    $perfectEvaluation = TextSolverEvaluation::evaluate($manifest, $perfect);
    assertSameValue(4, $perfectEvaluation['records'], 'perfect solver record count');
    foreach ($perfectEvaluation['profileSummary'] as $profile) {
        assertSameValue(1.0, $profile['worstVariantExactRate'], 'perfect solver worst variant exact rate');
        assertSameValue(1.0, $profile['exactAttemptRate'], 'perfect solver exact attempt rate');
    }

    $missing = $perfect;
    array_pop($missing['predictions']);
    expectRuntime(
        static fn () => TextSolverEvaluation::evaluate($manifest, $missing),
        'incomplete',
    );

    $duplicate = $perfect;
    $duplicate['predictions'][] = $duplicate['predictions'][0];
    expectRuntime(
        static fn () => TextSolverEvaluation::evaluate($manifest, $duplicate),
        'Duplicate prediction path',
    );

    $wrongCorpus = $perfect;
    $wrongCorpus['corpusId'] = str_repeat($corpusId[0] === '0' ? '1' : '0', 32);
    expectRuntime(
        static fn () => TextSolverEvaluation::evaluate($manifest, $wrongCorpus),
        'corpusId does not match',
    );

    putenv('JERVIS_CAPTCHA_SELFTEST_SECRET=must-not-leak');
    $environmentConfigPath = $root . '/environment.json';
    $environmentPredictionsPath = $root . '/environment-predictions.json';
    writeJson($environmentConfigPath, [
        'solver' => 'fixture-environment',
        'timeoutMs' => 3000,
        'command' => [PHP_BINARY, '-n', '-r', 'echo getenv("JERVIS_CAPTCHA_SELFTEST_SECRET") === false ? "clean" : "leaked";', '{input}'],
    ]);
    runCli([PHP_BINARY, __DIR__ . '/CommandTextSolver.php', $solverManifestPath, $environmentConfigPath, $environmentPredictionsPath]);
    $environmentPredictions = TextSolverEvaluation::loadJson($environmentPredictionsPath);
    assertSameValue('clean', $environmentPredictions['predictions'][0]['prediction'], 'solver environment sanitization');
    putenv('JERVIS_CAPTCHA_SELFTEST_SECRET');

    $captureConfigPath = $root . '/capture-limit.json';
    writeJson($captureConfigPath, [
        'solver' => 'fixture-capture-limit',
        'timeoutMs' => 3000,
        'command' => [PHP_BINARY, '-n', '-r', 'echo str_repeat("X", 5000);', '{input}'],
    ]);
    expectCliFailure(
        [PHP_BINARY, __DIR__ . '/CommandTextSolver.php', $solverManifestPath, $captureConfigPath, $root . '/capture.json'],
        'bounded stdout/stderr capture limits',
    );

    $comparison = TextSolverEvaluation::compare($manifest, [$perfect, $weak]);
    assertSameValue(2, $comparison['solverCount'], 'comparison solver count');
    assertSameValue(4, $comparison['recordsPerSolver'], 'comparison record count');
    foreach ($comparison['profileRisk'] as $profileRisk) {
        assertSameValue('fixture-perfect', $profileRisk['solver'], 'attacker-best solver');
        assertSameValue(1.0, $profileRisk['exactRate'], 'attacker-best exact rate');
    }

    $comparisonPath = $root . '/comparison.json';
    runCli([
        PHP_BINARY,
        __DIR__ . '/CompareTextSolvers.php',
        $manifestPath,
        $perfectPredictionsPath,
        $weakPredictionsPath,
        '--output=' . $comparisonPath,
    ]);
    $comparisonFromCli = TextSolverEvaluation::loadJson($comparisonPath);
    assertSameValue(2, $comparisonFromCli['solverCount'], 'comparison CLI solver count');
    assertSameValue($comparison['corpusFingerprint'], $comparisonFromCli['corpusFingerprint'], 'corpus fingerprint');

    fwrite(STDOUT, "adversarial harness self-test: OK\n");
} finally {
    removeTree($root);
}

function writeJson(string $path, array $value): void
{
    $json = TextSolverEvaluation::encodeJson($value);
    if (file_put_contents($path, $json, LOCK_EX) === false) {
        throw new RuntimeException("Unable to write fixture JSON: {$path}");
    }
}

function assertSameValue(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf(
            '%s mismatch: expected %s, got %s',
            $label,
            var_export($expected, true),
            var_export($actual, true),
        ));
    }
}

function expectRuntime(callable $callback, string $messageFragment): void
{
    try {
        $callback();
    } catch (RuntimeException $e) {
        if (str_contains($e->getMessage(), $messageFragment)) return;
        throw new RuntimeException("Unexpected RuntimeException: {$e->getMessage()}", 0, $e);
    }
    throw new RuntimeException("Expected RuntimeException containing: {$messageFragment}");
}

/** @param list<string> $command */
function runCli(array $command): void
{
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open($command, $descriptors, $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($process)) throw new RuntimeException('Unable to start adversarial harness fixture command.');
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    if ($exitCode !== 0) {
        throw new RuntimeException(sprintf(
            "Fixture command failed with exit code %d. stdout=%s stderr=%s",
            $exitCode,
            trim($stdout),
            trim($stderr),
        ));
    }
}

function removeTree(string $path): void
{
    if (!is_dir($path)) return;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($path);
}

/** @param list<string> $command */
function expectCliFailure(array $command, string $messageFragment): void
{
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open($command, $descriptors, $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($process)) throw new RuntimeException('Unable to start expected-failure fixture command.');
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    if ($exitCode === 0) throw new RuntimeException('Expected fixture command to fail.');
    $combined = $stdout . "\n" . $stderr;
    if (!str_contains($combined, $messageFragment)) {
        throw new RuntimeException("Expected failure containing '{$messageFragment}', got: " . trim($combined));
    }
}
