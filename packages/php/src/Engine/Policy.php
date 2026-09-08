<?php
declare(strict_types=1);
namespace Jervis\Captcha\Engine;
use InvalidArgumentException;
final readonly class Policy
{
    public const KINDS = ['text', 'slider', 'drag', 'rotate', 'click', 'math', 'logic', 'pow'];
    public function __construct(public array $types, public bool $isAntiAI, public int $workBits, public int $minSolveMs, public int $sessionTtlSeconds, public int $challengeTtlSeconds, public int $proofTtlSeconds, public int $maxAttempts, public int $maxRefreshes, public int $sessionsPerMinute) { $this->validate(); }
    public static function fromArray(array $value): self
    {
        return new self(array_values($value['types'] ?? ['text', 'slider', 'rotate']), (bool) ($value['isAntiAI'] ?? true), (int) ($value['workBits'] ?? 14), (int) ($value['minSolveMs'] ?? 400), (int) ($value['sessionTtlSeconds'] ?? 300), (int) ($value['challengeTtlSeconds'] ?? 120), (int) ($value['proofTtlSeconds'] ?? 60), (int) ($value['maxAttempts'] ?? 3), (int) ($value['maxRefreshes'] ?? 4), (int) ($value['sessionsPerMinute'] ?? 120));
    }
    public function toArray(): array { return get_object_vars($this); }
    private function validate(): void
    {
        if ($this->types === [] || count($this->types) > count(self::KINDS) || count(array_unique($this->types)) !== count($this->types)) throw new InvalidArgumentException('Invalid CAPTCHA types');
        foreach ($this->types as $kind) if (!in_array($kind, self::KINDS, true)) throw new InvalidArgumentException("Unsupported CAPTCHA type: {$kind}");
        if ($this->workBits < 0 || $this->workBits > 20 || (($this->isAntiAI || in_array('pow', $this->types, true)) && $this->workBits < 12)) throw new InvalidArgumentException('workBits must be 0..20 and at least 12 for antiAI/pow');
        if ($this->isAntiAI && (in_array('math', $this->types, true) || in_array('logic', $this->types, true))) throw new InvalidArgumentException('math/logic cannot be enabled in antiAI policies');
        if ($this->sessionTtlSeconds < 30 || $this->sessionTtlSeconds > 900 || $this->challengeTtlSeconds < 10 || $this->challengeTtlSeconds > $this->sessionTtlSeconds || $this->proofTtlSeconds < 5 || $this->proofTtlSeconds > 120 || $this->minSolveMs < 0 || $this->minSolveMs > 10000 || $this->maxAttempts < 1 || $this->maxAttempts > 5 || $this->maxRefreshes < 1 || $this->maxRefreshes > 10 || $this->sessionsPerMinute < 1 || $this->sessionsPerMinute > 10000) throw new InvalidArgumentException('CAPTCHA policy bounds are invalid');
    }
}
