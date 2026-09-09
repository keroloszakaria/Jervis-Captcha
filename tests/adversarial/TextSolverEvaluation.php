<?php
declare(strict_types=1);

final class TextSolverEvaluation
{
    public static function evaluate(array $manifest, array $predictionDocument): array
    {
        $expected = self::indexManifest($manifest);
        $solver = trim((string) ($predictionDocument['solver'] ?? ''));
        if ($solver === '') {
            throw new RuntimeException('Prediction document must declare a non-empty solver name.');
        }
        $corpusId = self::optionalCorpusId($manifest['corpusId'] ?? null, 'manifest');
        $predictionCorpusId = self::optionalCorpusId($predictionDocument['corpusId'] ?? null, "predictions for {$solver}");
        if ($corpusId !== null && $predictionCorpusId !== $corpusId) {
            throw new RuntimeException("Prediction corpusId does not match the evaluator manifest for {$solver}.");
        }

        $rows = $predictionDocument['predictions'] ?? null;
        if (!is_array($rows)) {
            throw new RuntimeException("Prediction document for {$solver} must contain a predictions array.");
        }

        $seen = [];
        $groups = [];
        foreach ($rows as $index => $prediction) {
            if (!is_array($prediction)) {
                throw new RuntimeException("Prediction #{$index} for {$solver} must be an object.");
            }
            self::accumulatePrediction($expected, $seen, $groups, $prediction, $solver, (int) $index);
        }

        self::assertCompleteCoverage($expected, $seen, $solver);
        $summary = self::buildVariantSummary($groups);
        $profileSummary = self::buildProfileSummary($summary);

        return [
            'solver' => $solver,
            'language' => (string) ($predictionDocument['language'] ?? ''),
            'corpusId' => $corpusId,
            'records' => count($rows),
            'corpusFingerprint' => self::corpusFingerprint($expected),
            'profileSummary' => $profileSummary,
            'summary' => $summary,
        ];
    }

    public static function compare(array $manifest, array $predictionDocuments): array
    {
        if (count($predictionDocuments) < 2) {
            throw new RuntimeException('CompareTextSolvers requires predictions from at least two solvers.');
        }

        $evaluations = [];
        $solverNames = [];
        foreach ($predictionDocuments as $document) {
            if (!is_array($document)) throw new RuntimeException('Every prediction document must be a JSON object.');
            $evaluation = self::evaluate($manifest, $document);
            $solver = $evaluation['solver'];
            if (isset($solverNames[$solver])) throw new RuntimeException("Duplicate solver name in comparison: {$solver}");
            $solverNames[$solver] = true;
            $evaluations[] = $evaluation;
        }

        $profileRisk = self::buildComparisonRisk($evaluations);
        return [
            'solverCount' => count($evaluations),
            'recordsPerSolver' => $evaluations[0]['records'],
            'corpusFingerprint' => $evaluations[0]['corpusFingerprint'],
            'profileRisk' => $profileRisk,
            'solvers' => $evaluations,
        ];
    }

    public static function loadJson(string $path): array
    {
        if (!is_file($path)) throw new RuntimeException("JSON file not found: {$path}");
        $raw = file_get_contents($path);
        if (!is_string($raw)) throw new RuntimeException("Unable to read JSON file: {$path}");
        if (str_starts_with($raw, "\xEF\xBB\xBF")) $raw = substr($raw, 3);
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) throw new RuntimeException("Expected JSON object in {$path}");
        return $decoded;
    }

    public static function encodeJson(array $value): string
    {
        return json_encode(
            $value,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ) . PHP_EOL;
    }

    private static function indexManifest(array $manifest): array
    {
        $records = $manifest['records'] ?? null;
        if (!is_array($records) || $records === []) {
            throw new RuntimeException('Corpus manifest must contain at least one record.');
        }

        $expected = [];
        foreach ($records as $index => $record) {
            if (!is_array($record)) throw new RuntimeException("Manifest record #{$index} must be an object.");
            foreach (['path', 'profile', 'variant', 'answer'] as $field) {
                if (!array_key_exists($field, $record) || !is_string($record[$field]) || $record[$field] === '') {
                    throw new RuntimeException("Manifest record #{$index} has an invalid {$field}.");
                }
            }
            $path = $record['path'];
            if (isset($expected[$path])) throw new RuntimeException("Duplicate manifest path: {$path}");
            $expected[$path] = $record;
        }
        ksort($expected);
        return $expected;
    }

    private static function accumulatePrediction(
        array $expected,
        array &$seen,
        array &$groups,
        array $prediction,
        string $solver,
        int $index,
    ): void {
        $path = $prediction['path'] ?? null;
        if (!is_string($path) || $path === '') throw new RuntimeException("Prediction #{$index} for {$solver} has an invalid path.");
        if (!isset($expected[$path])) throw new RuntimeException("Prediction path is not in manifest: {$path}");
        if (isset($seen[$path])) throw new RuntimeException("Duplicate prediction path for {$solver}: {$path}");
        $seen[$path] = true;

        $record = $expected[$path];
        foreach (['profile', 'variant'] as $field) {
            if (isset($prediction[$field]) && (string) $prediction[$field] !== $record[$field]) {
                throw new RuntimeException("Prediction metadata mismatch for {$path}: {$field}");
            }
        }
        if (!array_key_exists('prediction', $prediction) || !is_string($prediction['prediction'])) {
            throw new RuntimeException("Prediction for {$path} from {$solver} must be a string, including empty OCR results.");
        }

        $durationMs = 0.0;
        if (array_key_exists('durationMs', $prediction)) {
            if (!is_int($prediction['durationMs']) && !is_float($prediction['durationMs'])) {
                throw new RuntimeException("durationMs for {$path} from {$solver} must be numeric.");
            }
            $durationMs = (float) $prediction['durationMs'];
            if (!is_finite($durationMs) || $durationMs < 0) {
                throw new RuntimeException("durationMs for {$path} from {$solver} must be finite and non-negative.");
            }
        }

        $want = self::normalize($record['answer']);
        $got = self::normalize($prediction['prediction']);
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
        $groups[$key]['durationMsTotal'] += $durationMs;
    }

    private static function assertCompleteCoverage(array $expected, array $seen, string $solver): void
    {
        $missing = array_values(array_diff(array_keys($expected), array_keys($seen)));
        if ($missing === []) return;
        $preview = implode(', ', array_slice($missing, 0, 5));
        $suffix = count($missing) > 5 ? sprintf(' (+%d more)', count($missing) - 5) : '';
        throw new RuntimeException(sprintf(
            '%s predictions are incomplete: %d manifest records are missing: %s%s',
            $solver,
            count($missing),
            $preview,
            $suffix,
        ));
    }

    private static function buildVariantSummary(array $groups): array
    {
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
        return $summary;
    }

    private static function buildProfileSummary(array $summary): array
    {
        $profiles = [];
        foreach ($summary as $row) {
            $profile = (string) $row['profile'];
            $profiles[$profile] ??= [
                'profile' => $profile, 'attempts' => 0, 'exactMatches' => 0,
                'worstVariant' => '', 'worstVariantExactRate' => -1.0,
                'highestVariantCharAccuracy' => 0.0,
            ];
            $profiles[$profile]['attempts'] += (int) $row['count'];
            $profiles[$profile]['exactMatches'] += (int) $row['exactMatches'];
            if ((float) $row['exactRate'] > $profiles[$profile]['worstVariantExactRate']) {
                $profiles[$profile]['worstVariantExactRate'] = (float) $row['exactRate'];
                $profiles[$profile]['worstVariant'] = (string) $row['variant'];
            }
            $profiles[$profile]['highestVariantCharAccuracy'] = max(
                $profiles[$profile]['highestVariantCharAccuracy'],
                (float) $row['meanCharAccuracy'],
            );
        }
        foreach ($profiles as &$profile) {
            $profile['exactAttemptRate'] = round(
                $profile['exactMatches'] / max(1, $profile['attempts']),
                4,
            );
        }
        unset($profile);
        $profiles = array_values($profiles);
        usort($profiles, static fn (array $a, array $b): int => $a['profile'] <=> $b['profile']);
        return $profiles;
    }

    private static function buildComparisonRisk(array $evaluations): array
    {
        $profiles = [];
        foreach ($evaluations as $evaluation) {
            foreach ($evaluation['profileSummary'] as $profile) {
                $name = (string) $profile['profile'];
                $candidate = [
                    'profile' => $name,
                    'solver' => $evaluation['solver'],
                    'variant' => $profile['worstVariant'],
                    'exactRate' => (float) $profile['worstVariantExactRate'],
                    'exactAttemptRate' => (float) $profile['exactAttemptRate'],
                    'highestVariantCharAccuracy' => (float) $profile['highestVariantCharAccuracy'],
                ];
                if (!isset($profiles[$name]) || self::riskIsHigher($candidate, $profiles[$name])) {
                    $profiles[$name] = $candidate;
                }
            }
        }
        $profiles = array_values($profiles);
        usort($profiles, static fn (array $a, array $b): int => $a['profile'] <=> $b['profile']);
        return $profiles;
    }

    private static function riskIsHigher(array $candidate, array $current): bool
    {
        foreach (['exactRate', 'exactAttemptRate', 'highestVariantCharAccuracy'] as $field) {
            if ($candidate[$field] > $current[$field]) return true;
            if ($candidate[$field] < $current[$field]) return false;
        }
        $solverOrder = strcmp((string) $candidate['solver'], (string) $current['solver']);
        if ($solverOrder !== 0) return $solverOrder < 0;
        return strcmp((string) $candidate['variant'], (string) $current['variant']) < 0;
    }

    private static function optionalCorpusId(mixed $value, string $source): ?string
    {
        if ($value === null || $value === '') return null;
        if (!is_string($value) || preg_match('/^[a-f0-9]{32}$/D', $value) !== 1) {
            throw new RuntimeException("Invalid corpusId in {$source}; expected 32 lowercase hexadecimal characters.");
        }
        return $value;
    }

    private static function corpusFingerprint(array $expected): string
    {
        $fingerprintRows = [];
        foreach ($expected as $record) {
            $fingerprintRows[] = [
                'path' => $record['path'],
                'profile' => $record['profile'],
                'variant' => $record['variant'],
                'answer' => $record['answer'],
            ];
        }
        return hash('sha256', json_encode($fingerprintRows, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private static function normalize(string $value): string
    {
        return strtoupper((string) preg_replace('/[^A-Z0-9]/i', '', $value));
    }
}
