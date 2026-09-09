<?php
declare(strict_types=1);

require __DIR__ . '/TextSolverEvaluation.php';

$manifestPath = $argv[1] ?? '.adversarial-text-corpus/manifest.json';
$configPath = $argv[2] ?? null;
$outputPath = $argv[3] ?? null;
if (!is_string($configPath) || $configPath === '' || !is_string($outputPath) || $outputPath === '') {
    fwrite(STDERR, "usage: php CommandTextSolver.php <manifest.json> <solver-config.json> <predictions.json>" . PHP_EOL);
    exit(2);
}

try {
    $manifest = TextSolverEvaluation::loadJson($manifestPath);
    $config = TextSolverEvaluation::loadJson($configPath);
    $solver = nonEmptyString($config, 'solver');
    $language = isset($config['language']) ? (string) $config['language'] : '';
    $commandTemplate = commandTemplate($config);
    $timeoutMs = boundedTimeout($config['timeoutMs'] ?? 5000);
    $environment = solverEnvironment($config);
    $records = $manifest['records'] ?? null;
    if (!is_array($records) || $records === []) throw new RuntimeException('Manifest contains no records.');

    $predictions = [];
    foreach ($records as $index => $record) {
        if (!is_array($record) || !isset($record['path']) || !is_string($record['path'])) {
            throw new RuntimeException("Manifest record #{$index} has no valid path.");
        }
        $inputPath = resolveInputPath($record['path'], $manifestPath);
        $command = materializeCommand($commandTemplate, $inputPath);
        $started = hrtime(true);
        [$stdout, $stderr, $exitCode] = runCommand($command, $timeoutMs, $environment);
        $durationMs = (hrtime(true) - $started) / 1_000_000;
        if ($exitCode !== 0) {
            $message = trim($stderr) !== '' ? trim($stderr) : trim($stdout);
            throw new RuntimeException(sprintf(
                'Solver %s failed for %s with exit code %d: %s',
                $solver,
                $record['path'],
                $exitCode,
                substr($message, 0, 1000),
            ));
        }
        $predictions[] = [
            'profile' => (string) ($record['profile'] ?? ''),
            'variant' => (string) ($record['variant'] ?? ''),
            'path' => $record['path'],
            'prediction' => trim($stdout),
            'durationMs' => round($durationMs, 3),
        ];
    }

    $document = [
        'solver' => $solver,
        'language' => $language,
        'corpusId' => isset($manifest['corpusId']) ? (string) $manifest['corpusId'] : null,
        'adapter' => 'local-command-v1',
        'generatedAtUtc' => gmdate(DATE_ATOM),
        'predictions' => $predictions,
    ];
    $json = TextSolverEvaluation::encodeJson($document);
    $directory = dirname($outputPath);
    if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
        throw new RuntimeException("Unable to create output directory: {$directory}");
    }
    if (file_put_contents($outputPath, $json, LOCK_EX) === false) {
        throw new RuntimeException("Unable to write predictions: {$outputPath}");
    }
    fwrite(STDOUT, sprintf("wrote %d predictions for %s to %s\n", count($predictions), $solver, $outputPath));
} catch (Throwable $e) {
    fwrite(STDERR, 'solver adapter failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

function nonEmptyString(array $config, string $field): string
{
    $value = $config[$field] ?? null;
    if (!is_string($value) || trim($value) === '') {
        throw new RuntimeException("Solver config field {$field} must be a non-empty string.");
    }
    return trim($value);
}

/** @return list<string> */
function commandTemplate(array $config): array
{
    $command = $config['command'] ?? null;
    if (!is_array($command) || $command === []) {
        throw new RuntimeException('Solver config command must be a non-empty JSON string array.');
    }
    $placeholderCount = 0;
    $normalized = [];
    foreach ($command as $index => $part) {
        if (!is_string($part) || $part === '') {
            throw new RuntimeException("Solver command argument #{$index} must be a non-empty string.");
        }
        $placeholderCount += substr_count($part, '{input}');
        $normalized[] = $part;
    }
    if ($placeholderCount < 1) {
        throw new RuntimeException('Solver command must contain at least one {input} placeholder.');
    }
    return $normalized;
}

function boundedTimeout(mixed $value): int
{
    if (!is_int($value)) throw new RuntimeException('Solver timeoutMs must be an integer.');
    if ($value < 100 || $value > 60_000) {
        throw new RuntimeException('Solver timeoutMs must be between 100 and 60000 milliseconds.');
    }
    return $value;
}

/** @return array<string,string> */
function solverEnvironment(array $config): array
{
    $environment = [];
    foreach (['PATH', 'SystemRoot', 'WINDIR', 'TEMP', 'TMP', 'HOME', 'USERPROFILE', 'LANG', 'LC_ALL'] as $name) {
        $value = getenv($name);
        if (is_string($value) && $value !== '') $environment[$name] = $value;
    }

    $extra = $config['environment'] ?? [];
    if (!is_array($extra)) throw new RuntimeException('Solver environment must be a JSON object of explicit string values.');
    foreach ($extra as $name => $value) {
        if (!is_string($name) || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $name) !== 1) {
            throw new RuntimeException('Solver environment contains an invalid variable name.');
        }
        if (!is_string($value) || strlen($value) > 4096) {
            throw new RuntimeException("Solver environment value for {$name} must be a string up to 4096 bytes.");
        }
        $environment[$name] = $value;
    }
    return $environment;
}

function resolveInputPath(string $recordPath, string $manifestPath): string
{
    if (is_file($recordPath)) {
        $resolved = realpath($recordPath);
        return $resolved !== false ? $resolved : $recordPath;
    }
    $candidate = dirname($manifestPath) . DIRECTORY_SEPARATOR . $recordPath;
    if (!is_file($candidate)) throw new RuntimeException("Corpus image not found: {$recordPath}");
    $resolved = realpath($candidate);
    return $resolved !== false ? $resolved : $candidate;
}

/** @param list<string> $template @return list<string> */
function materializeCommand(array $template, string $inputPath): array
{
    return array_map(
        static fn (string $part): string => str_replace('{input}', $inputPath, $part),
        $template,
    );
}

/** @param list<string> $command @param array<string,string> $environment @return array{string,string,int} */
function runCommand(array $command, int $timeoutMs, array $environment): array
{
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptors, $pipes, null, $environment, ['bypass_shell' => true]);
    if (!is_resource($process)) throw new RuntimeException('Unable to start local solver process.');
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $stdout = '';
    $stderr = '';
    $deadline = microtime(true) + ($timeoutMs / 1000);
    $lastStatus = null;
    $timedOut = false;
    $captureFailure = null;

    try {
        while (true) {
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);
            if (strlen($stdout) > 4096 || strlen($stderr) > 65_536) {
                proc_terminate($process);
                $captureFailure = 'Local solver exceeded bounded stdout/stderr capture limits.';
                break;
            }
            $lastStatus = proc_get_status($process);
            if (!$lastStatus['running']) break;
            if (microtime(true) >= $deadline) {
                proc_terminate($process);
                usleep(100_000);
                $status = proc_get_status($process);
                if ($status['running']) proc_terminate($process, 9);
                $timedOut = true;
                break;
            }
            usleep(10_000);
        }
        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);
        if (strlen($stdout) > 4096 || strlen($stderr) > 65_536) {
            $captureFailure ??= 'Local solver exceeded bounded stdout/stderr capture limits.';
        }
    } finally {
        fclose($pipes[1]);
        fclose($pipes[2]);
    }

    if ($captureFailure !== null) {
        usleep(100_000);
        $status = proc_get_status($process);
        if ($status['running']) proc_terminate($process, 9);
    }
    $closedExitCode = proc_close($process);
    if ($timedOut) throw new RuntimeException("Local solver exceeded {$timeoutMs} ms timeout.");
    if ($captureFailure !== null) throw new RuntimeException($captureFailure);
    $exitCode = $closedExitCode;
    if (is_array($lastStatus) && !$lastStatus['running'] && (int) $lastStatus['exitcode'] >= 0) {
        $exitCode = (int) $lastStatus['exitcode'];
    }
    return [$stdout, $stderr, $exitCode];
}
