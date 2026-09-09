<?php
declare(strict_types=1);

require_once __DIR__ . '/TextSolverEvaluation.php';

final class LmStudioVisionSolver
{
    private const MAX_IMAGE_BYTES = 4_194_304;
    private const MAX_RESPONSE_BYTES = 1_048_576;
    private const PROMPT = 'Read the CAPTCHA image. Return only the six-character uppercase letters/digits string. No spaces or explanation.';

    public static function main(array $argv): int
    {
        try {
            $manifestPath = $argv[1] ?? '';
            $outputPath = $argv[2] ?? '';
            $model = self::validateModel((string) ($argv[3] ?? ''));
            $endpoint = self::validateEndpoint((string) ($argv[4] ?? 'http://127.0.0.1:1234'));
            $timeoutSeconds = self::validateTimeout($argv[5] ?? 120);
            if ($manifestPath === '' || $outputPath === '') {
                throw new RuntimeException('Usage: php LmStudioVisionSolver.php <solver-manifest.json> <predictions.json> <model-id> [endpoint] [timeout-seconds]');
            }

            $manifest = TextSolverEvaluation::loadJson($manifestPath);
            $corpusId = self::requireCorpusId($manifest);
            $records = self::solverRecords($manifest);
            $predictions = [];
            foreach ($records as $record) {
                $path = (string) $record['path'];
                $started = hrtime(true);
                $content = self::solveImage($path, $model, $endpoint, $timeoutSeconds);
                $predictions[] = [
                    'profile' => (string) $record['profile'],
                    'variant' => (string) $record['variant'],
                    'path' => $path,
                    'prediction' => self::extractPrediction($content),
                    'durationMs' => round((hrtime(true) - $started) / 1_000_000, 3),
                ];
            }

            $document = [
                'solver' => 'lm-studio:' . $model,
                'language' => 'vision',
                'corpusId' => $corpusId,
                'adapter' => 'lm-studio-native-v1-local-only',
                'generatedAtUtc' => gmdate(DATE_ATOM),
                'predictions' => $predictions,
            ];
            self::writeJson($outputPath, $document);
            fwrite(STDOUT, sprintf("wrote %d VLM predictions to %s\n", count($predictions), $outputPath));
            return 0;
        } catch (Throwable $e) {
            fwrite(STDERR, $e->getMessage() . PHP_EOL);
            return 1;
        }
    }
    public static function validateEndpoint(string $endpoint): string
    {
        $endpoint = rtrim(trim($endpoint), '/');
        $parts = parse_url($endpoint);
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'http') {
            throw new RuntimeException('LM Studio benchmark endpoint must use plain HTTP on loopback.');
        }
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));
        if (!in_array($host, ['127.0.0.1', '::1'], true)) {
            throw new RuntimeException('LM Studio benchmark endpoint must use a numeric loopback address; DNS names and remote VLM endpoints are rejected.');
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new RuntimeException('LM Studio benchmark endpoint must not contain credentials, query parameters or fragments.');
        }
        $path = (string) ($parts['path'] ?? '');
        if ($path !== '' && $path !== '/') {
            throw new RuntimeException('LM Studio benchmark endpoint must be an origin without a path.');
        }
        $port = (int) ($parts['port'] ?? 1234);
        if ($port < 1 || $port > 65535) throw new RuntimeException('LM Studio benchmark port is invalid.');
        $renderHost = $host === '::1' ? '[::1]' : $host;
        return "http://{$renderHost}:{$port}";
    }

    public static function extractPrediction(string $content): string
    {
        $matches = [];
        if (preg_match_all('/(?<![A-Z0-9])([A-Z0-9]{6})(?![A-Z0-9])/i', strtoupper($content), $matches) > 0) {
            $candidates = $matches[1];
            $last = end($candidates);
            if (is_string($last)) return $last;
        }
        return trim($content);
    }
    /** @return array<int,array{profile:string,variant:string,path:string}> */
    private static function solverRecords(array $manifest): array
    {
        $records = $manifest['records'] ?? null;
        if (!is_array($records) || $records === []) throw new RuntimeException('Solver manifest has no records.');
        $result = [];
        $seen = [];
        foreach ($records as $index => $record) {
            if (!is_array($record)) throw new RuntimeException("Solver manifest record {$index} is invalid.");
            if (array_key_exists('answer', $record)) {
                throw new RuntimeException('Refusing solver manifest containing expected answers; use solver-manifest.json.');
            }
            $profile = (string) ($record['profile'] ?? '');
            $variant = (string) ($record['variant'] ?? '');
            $path = (string) ($record['path'] ?? '');
            if ($profile === '' || $variant === '' || $path === '') {
                throw new RuntimeException("Solver manifest record {$index} is incomplete.");
            }
            if (isset($seen[$path])) throw new RuntimeException("Duplicate solver manifest path: {$path}");
            $seen[$path] = true;
            $result[] = compact('profile', 'variant', 'path');
        }
        return $result;
    }

    private static function requireCorpusId(array $manifest): string
    {
        $corpusId = $manifest['corpusId'] ?? null;
        if (!is_string($corpusId) || preg_match('/^[a-f0-9]{32}$/D', $corpusId) !== 1) {
            throw new RuntimeException('Solver manifest requires a valid 32-character lowercase hexadecimal corpusId.');
        }
        return $corpusId;
    }
    private static function validateModel(string $model): string
    {
        $model = trim($model);
        if ($model === '' || strlen($model) > 200 || preg_match('/[\x00-\x1F\x7F]/', $model) === 1) {
            throw new RuntimeException('A bounded LM Studio model identifier is required.');
        }
        return $model;
    }

    private static function validateTimeout(mixed $value): int
    {
        if (is_string($value) && preg_match('/^\d+$/D', $value) !== 1) {
            throw new RuntimeException('LM Studio timeout must be an integer number of seconds.');
        }
        $timeout = (int) $value;
        if ($timeout < 1 || $timeout > 300) throw new RuntimeException('LM Studio timeout must be between 1 and 300 seconds.');
        return $timeout;
    }

    private static function solveImage(string $path, string $model, string $endpoint, int $timeoutSeconds): string
    {
        if (!is_file($path)) throw new RuntimeException("Benchmark image does not exist: {$path}");
        $size = filesize($path);
        if (!is_int($size) || $size < 1 || $size > self::MAX_IMAGE_BYTES) {
            throw new RuntimeException("Benchmark image exceeds the local VLM size bound: {$path}");
        }
        $raw = file_get_contents($path);
        if (!is_string($raw) || $raw === '') throw new RuntimeException("Unable to read benchmark image: {$path}");
        $mime = self::mimeForPath($path);
        $payload = json_encode([
            'model' => $model,
            'reasoning' => 'off',
            'temperature' => 0,
            'max_output_tokens' => 24,
            'store' => false,
            'input' => [
                ['type' => 'text', 'content' => self::PROMPT],
                ['type' => 'image', 'data_url' => 'data:' . $mime . ';base64,' . base64_encode($raw)],
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $response = self::postJson($endpoint . '/api/v1/chat', $payload, $timeoutSeconds);
        $decoded = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || !is_array($decoded['output'] ?? null)) {
            throw new RuntimeException('LM Studio returned an invalid response shape.');
        }
        foreach ($decoded['output'] as $item) {
            if (is_array($item) && ($item['type'] ?? null) === 'message' && is_string($item['content'] ?? null)) {
                return $item['content'];
            }
        }
        throw new RuntimeException('LM Studio response did not contain a final message.');
    }

    private static function mimeForPath(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            default => throw new RuntimeException('LM Studio benchmark accepts only PNG/JPEG images.'),
        };
    }
    private static function postJson(string $url, string $payload, int $timeoutSeconds): string
    {
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nAccept: application/json\r\nConnection: close\r\n",
            'content' => $payload,
            'timeout' => $timeoutSeconds,
            'ignore_errors' => true,
            'follow_location' => 0,
            'max_redirects' => 0,
            'protocol_version' => 1.1,
        ]]);
        $stream = @fopen($url, 'rb', false, $context);
        if (!is_resource($stream)) throw new RuntimeException('Unable to connect to the local LM Studio endpoint.');
        $body = '';
        try {
            while (!feof($stream)) {
                $chunk = fread($stream, 8192);
                if ($chunk === false) throw new RuntimeException('Unable to read the LM Studio response.');
                $body .= $chunk;
                if (strlen($body) > self::MAX_RESPONSE_BYTES) throw new RuntimeException('LM Studio response exceeded the size bound.');
            }
            $meta = stream_get_meta_data($stream);
        } finally {
            fclose($stream);
        }
        if (($meta['timed_out'] ?? false) === true) throw new RuntimeException('LM Studio request timed out.');
        $status = self::httpStatus($meta['wrapper_data'] ?? []);
        if ($status !== 200) throw new RuntimeException("LM Studio returned HTTP {$status}.");
        return $body;
    }
    private static function httpStatus(mixed $headers): int
    {
        if (is_string($headers)) $headers = [$headers];
        if (!is_array($headers)) return 0;
        $status = 0;
        foreach ($headers as $header) {
            if (is_string($header) && preg_match('#^HTTP/\S+\s+(\d{3})#i', $header, $match) === 1) {
                $status = (int) $match[1];
            }
        }
        return $status;
    }

    private static function writeJson(string $path, array $value): void
    {
        $directory = dirname($path);
        if ($directory !== '.' && !is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException("Unable to create output directory: {$directory}");
        }
        $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
        if (file_put_contents($path, $json, LOCK_EX) === false) throw new RuntimeException("Unable to write output: {$path}");
    }
}

if (PHP_SAPI === 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && realpath((string) $_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    exit(LmStudioVisionSolver::main($argv));
}
