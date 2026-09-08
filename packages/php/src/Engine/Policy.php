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
        return new self(
            array_values($value['types'] ?? ['text', 'slider', 'rotate']),
            array_values($value['accessibilityTypes'] ?? ['audio']),
            (bool) ($value['isAntiAI'] ?? true),
            (int) ($value['workBits'] ?? 14),
            (int) ($value['minSolveMs'] ?? 400),
            (int) ($value['sessionTtlSeconds'] ?? 300),
            (int) ($value['challengeTtlSeconds'] ?? 120),
            (int) ($value['proofTtlSeconds'] ?? 60),
            (int) ($value['maxAttempts'] ?? 3),
            (int) ($value['maxRefreshes'] ?? 4),
            (int) ($value['sessionsPerMinute'] ?? 120),
        );
    }

    public static function completeArray(array $value): bool
    {
        foreach (self::KEYS as $key) if (!array_key_exists($key, $value)) return false;
        return true;
    }

    public function toArray(): array { return get_object_vars($this); }
    public function accessibilityAvailable(): bool { return $this->accessibilityTypes !== []; }

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
