<?php
declare(strict_types=1);

namespace Jervis\Captcha\Engine;

use InvalidArgumentException;

final readonly class Policy
{
    public const KINDS = ['text', 'slider', 'drag', 'rotate', 'click', 'audio', 'math', 'logic', 'pow'];
    public const KEYS = [
        'types', 'accessibilityTypes', 'isAntiAI', 'workBits', 'minSolveMs',
        'sessionTtlSeconds', 'challengeTtlSeconds', 'proofTtlSeconds',
        'maxAttempts', 'maxRefreshes', 'sessionsPerMinute',
    ];

    public function __construct(
        public array $types,
        public array $accessibilityTypes,
        public bool $isAntiAI,
        public int $workBits,
        public int $minSolveMs,
        public int $sessionTtlSeconds,
        public int $challengeTtlSeconds,
        public int $proofTtlSeconds,
        public int $maxAttempts,
        public int $maxRefreshes,
        public int $sessionsPerMinute,
    ) {
        $this->validate();
    }

    public static function fromArray(array $value): self
    {
        self::assertKnownKeys($value);
        return new self(
            self::types($value, 'types', ['text', 'slider', 'rotate']),
            self::types($value, 'accessibilityTypes', ['audio']),
            self::boolean($value, 'isAntiAI', true),
            self::integer($value, 'workBits', 14),
            self::integer($value, 'minSolveMs', 400),
            self::integer($value, 'sessionTtlSeconds', 300),
            self::integer($value, 'challengeTtlSeconds', 120),
            self::integer($value, 'proofTtlSeconds', 60),
            self::integer($value, 'maxAttempts', 3),
            self::integer($value, 'maxRefreshes', 4),
            self::integer($value, 'sessionsPerMinute', 120),
        );
    }

    public static function completeArray(array $value): bool
    {
        try { self::assertKnownKeys($value); } catch (InvalidArgumentException) { return false; }
        foreach (self::KEYS as $key) if (!array_key_exists($key, $value)) return false;
        return true;
    }

    public function toArray(): array { return get_object_vars($this); }
    public function accessibilityAvailable(): bool { return $this->accessibilityTypes !== []; }

    /** @param array<string,mixed> $value */
    private static function assertKnownKeys(array $value): void
    {
        foreach (array_keys($value) as $key) {
            if (!is_string($key) || !in_array($key, self::KEYS, true)) {
                throw new InvalidArgumentException('Unknown CAPTCHA policy field');
            }
        }
    }

    /** @param array<string,mixed> $value @param list<string> $default @return list<string> */
    private static function types(array $value, string $key, array $default): array
    {
        if (!array_key_exists($key, $value)) return $default;
        if (!is_array($value[$key])) throw new InvalidArgumentException("{$key} must be an array");
        return array_values($value[$key]);
    }

    /** @param array<string,mixed> $value */
    private static function boolean(array $value, string $key, bool $default): bool
    {
        if (!array_key_exists($key, $value)) return $default;
        $raw = $value[$key];
        if (is_bool($raw)) return $raw;
        if ($raw === 1 || $raw === '1' || $raw === 'true') return true;
        if ($raw === 0 || $raw === '0' || $raw === 'false') return false;
        throw new InvalidArgumentException("{$key} must be a boolean");
    }

    /** @param array<string,mixed> $value */
    private static function integer(array $value, string $key, int $default): int
    {
        if (!array_key_exists($key, $value)) return $default;
        $raw = $value[$key];
        if (is_int($raw)) return $raw;
        if (is_string($raw) && preg_match('/^-?(?:0|[1-9][0-9]*)$/D', $raw) === 1) return (int) $raw;
        throw new InvalidArgumentException("{$key} must be an integer");
    }

    private function validate(): void
    {
        $this->validateTypes($this->types, false);
        $this->validateTypes($this->accessibilityTypes, true);

        $allTypes = array_values(array_unique([...$this->types, ...$this->accessibilityTypes]));
        if ($this->workBits < 0 || $this->workBits > 20 || (($this->isAntiAI || in_array('pow', $allTypes, true)) && $this->workBits < 12)) {
            throw new InvalidArgumentException('workBits must be 0..20 and at least 12 for antiAI/pow');
        }
        if ($this->isAntiAI && (in_array('math', $allTypes, true) || in_array('logic', $allTypes, true))) {
            throw new InvalidArgumentException('math/logic cannot be enabled in antiAI policies');
        }
        if ($this->sessionTtlSeconds < 30 || $this->sessionTtlSeconds > 900
            || $this->challengeTtlSeconds < 10 || $this->challengeTtlSeconds > $this->sessionTtlSeconds
            || $this->proofTtlSeconds < 5 || $this->proofTtlSeconds > 120
            || $this->minSolveMs < 0 || $this->minSolveMs > 10000
            || $this->maxAttempts < 1 || $this->maxAttempts > 5
            || $this->maxRefreshes < 1 || $this->maxRefreshes > 10
            || $this->sessionsPerMinute < 1 || $this->sessionsPerMinute > 10000) {
            throw new InvalidArgumentException('CAPTCHA policy bounds are invalid');
        }
    }

    private function validateTypes(array $types, bool $allowEmpty): void
    {
        if ((!$allowEmpty && $types === []) || count($types) > count(self::KINDS) || count(array_unique($types)) !== count($types)) {
            throw new InvalidArgumentException('Invalid CAPTCHA types');
        }
        foreach ($types as $kind) {
            if (!is_string($kind) || !in_array($kind, self::KINDS, true)) {
                throw new InvalidArgumentException('Unsupported CAPTCHA type');
            }
        }
    }
}
