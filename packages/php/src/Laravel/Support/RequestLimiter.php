<?php
declare(strict_types=1);

namespace Jervis\Captcha\Laravel\Support;

use Jervis\Captcha\CaptchaException;
use Jervis\Captcha\Contracts\Store;

final readonly class RequestLimiter
{
    private const OPERATIONS = ['challenge', 'answer'];

    public function __construct(private Store $store) {}

    public function assertAllowed(string $operation, string $binding): void
    {
        if (!in_array($operation, self::OPERATIONS, true)) {
            throw new \InvalidArgumentException('Unsupported CAPTCHA request budget operation');
        }

        $limits = (array) config('jervis-captcha.request_limits', []);
        $global = $this->limit($limits, 'global_per_minute', 5000);
        $perBinding = $this->limit(
            $limits,
            $operation . '_per_binding_per_minute',
            $operation === 'challenge' ? 30 : 60,
        );

        $bindingKey = hash('sha256', $binding);
        if (!$this->store->rateLimit('request:global', $global, 60)
            || !$this->store->rateLimit("request:{$operation}:binding:{$bindingKey}", $perBinding, 60)) {
            throw new CaptchaException('rate_limited', 429);
        }
    }

    /** @param array<string,mixed> $limits */
    private function limit(array $limits, string $key, int $default): int
    {
        $value = array_key_exists($key, $limits) ? (int) $limits[$key] : $default;
        if ($value < 1 || $value > 100000) {
            throw new CaptchaException('verification_unavailable', 503);
        }
        return $value;
    }
}
