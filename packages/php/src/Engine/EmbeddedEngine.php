<?php
declare(strict_types=1);

namespace Jervis\Captcha\Engine;

use Jervis\Captcha\CaptchaException;
use Jervis\Captcha\Contracts\Store;

final class EmbeddedEngine
{
    public const VERSION = '0.2.0-alpha.1';

    /** @var array<string, Policy> */
    private array $actions = [];
    private readonly ?Policy $defaultPolicy;

    /**
     * @param array<string, Policy|array<string,mixed>> $actions
     * @param Policy|array<string,mixed>|null $defaultPolicy
     */
    public function __construct(
        private readonly Store $store,
        private readonly string $secret,
        array $actions = [],
        Policy|array|null $defaultPolicy = null,
        private readonly ?ChallengeGenerator $generator = null,
        private readonly int $globalSessionsPerMinute = 300,
    ) {
        if (strlen($secret) < 32) {
            throw new \InvalidArgumentException('Jervis Captcha secret must be at least 32 bytes');
        }
        if ($globalSessionsPerMinute < 1 || $globalSessionsPerMinute > 10000) {
            throw new \InvalidArgumentException('Invalid global CAPTCHA rate limit');
        }
        $this->defaultPolicy = $defaultPolicy === null
            ? null
            : ($defaultPolicy instanceof Policy ? $defaultPolicy : Policy::fromArray($defaultPolicy));
        if ($actions === [] && $this->defaultPolicy === null) {
            throw new \InvalidArgumentException('At least one CAPTCHA action or a default policy is required');
        }
        foreach ($actions as $action => $policy) {
            if (!is_string($action) || !$this->validAction($action)) {
                throw new \InvalidArgumentException('Invalid CAPTCHA action');
            }
            if ($policy instanceof Policy) {
                $this->actions[$action] = $policy;
                continue;
            }
            $override = (array) $policy;
            $base = $this->defaultPolicy?->toArray() ?? [];
            $this->actions[$action] = Policy::fromArray(array_replace($base, $override));
        }
    }

    public function createSession(string $action, string $binding, string $variant = 'standard'): array
    {
        $policy = $this->policy($action);
        $this->validateBinding($binding);
        if (!in_array($variant, ['standard', 'accessible'], true)) {
            throw new CaptchaException('invalid_context', 400);
        }

        $pool = $variant === 'accessible' ? $policy->accessibilityTypes : $policy->types;
        if ($pool === []) {
            throw new CaptchaException('accessibility_unavailable', 400);
        }
        $kind = $pool[array_rand($pool)];

        if (!$this->store->rateLimit('site', $this->globalSessionsPerMinute, 60)
            || !$this->store->rateLimit("site:{$action}", $policy->sessionsPerMinute, 60)) {
            throw new CaptchaException('rate_limited', 429);
        }
        $bound = $this->bind($binding);
        if (!$this->store->rateLimit("binding:{$bound}", min(30, $this->globalSessionsPerMinute), 60)
            || !$this->store->rateLimit("binding:{$action}:{$bound}", min(12, $policy->sessionsPerMinute), 60)) {
            throw new CaptchaException('rate_limited', 429);
        }

        $token = ChallengeGenerator::token();
        $now = self::now();
        $ttl = $policy->sessionTtlSeconds * 1000;
        $state = [
            'id' => hash('sha256', $token), 'action' => $action, 'binding' => $bound, 'type' => $kind,
            'variant' => $variant, 'policy' => $policy->toArray(), 'createdMs' => $now, 'expiresMs' => $now + $ttl,
            'state' => 'new', 'refreshes' => 0, 'attempts' => 0, 'challenge' => null, 'expected' => null,
            'challengeCreatedMs' => 0, 'challengeExpiresMs' => 0, 'proofHash' => '', 'proofExpiresMs' => 0,
        ];
        $this->store->create($state['id'], $state, $policy->sessionTtlSeconds);
        return [
            'sessionToken' => $token,
            'expiresInMs' => $ttl,
            'accessibilityAvailable' => $policy->accessibilityAvailable(),
        ];
    }

    public function issue(string $sessionToken, string $binding): array
    {
        $this->validateBinding($binding);
        $id = $this->sessionId($sessionToken);
        $now = self::now();
        return $this->store->transaction($id, 900, function (?array $state) use ($now, $binding): array {
            $state = $this->active($state, $binding, $now);
            $policy = Policy::fromArray($state['policy']);
            if (in_array($state['state'], ['solved', 'consumed'], true)) {
                throw new CaptchaException('session_finished', 409);
            }
            if ($state['attempts'] >= $policy->maxAttempts || $state['refreshes'] >= $policy->maxRefreshes) {
                throw new CaptchaException('session_locked', 429);
            }
            [$challenge, $expected] = ($this->generator ?? new ChallengeGenerator())->generate($state['type'], $policy->isAntiAI);
            if ($policy->isAntiAI || $state['type'] === 'pow') {
                $challenge['work'] = ['seed' => ChallengeGenerator::token(), 'bits' => $policy->workBits];
            }
            $expires = min($state['expiresMs'], $now + ($policy->challengeTtlSeconds * 1000));
            $challenge['expiresInMs'] = $expires - $now;
            $challenge['minimumDelayMs'] = $policy->minSolveMs;
            $state['state'] = 'challenge';
            $state['refreshes']++;
            $state['challengeCreatedMs'] = $now;
            $state['challengeExpiresMs'] = $expires;
            // Persist only verification metadata. Rendered image/audio payloads are
            // returned to this request and do not belong in the cache-backed state.
            $state['challenge'] = [
                'id' => $challenge['id'],
                'work' => $challenge['work'] ?? null,
            ];
            $state['expected'] = $expected;
            return ['state' => $state, 'result' => $challenge];
        });
    }

    public function solve(string $sessionToken, string $binding, string $challengeId, array $answer, ?string $workNonce = null): array
    {
        $this->validateBinding($binding);
        $id = $this->sessionId($sessionToken);
        $now = self::now();
        return $this->store->transaction($id, 900, function (?array $state) use ($now, $binding, $challengeId, $answer, $workNonce): array {
            $state = $this->active($state, $binding, $now);
            $policy = Policy::fromArray($state['policy']);
            if ($state['state'] !== 'challenge' || !hash_equals((string) ($state['challenge']['id'] ?? ''), $challengeId)) {
                throw new CaptchaException('invalid_challenge', 409);
            }
            if ($now >= $state['challengeExpiresMs']) {
                throw new CaptchaException('challenge_expired', 410);
            }
            if ($state['attempts'] >= $policy->maxAttempts) {
                throw new CaptchaException('session_locked', 429);
            }
            if (($now - $state['challengeCreatedMs']) < $policy->minSolveMs) {
                throw new CaptchaException('too_early', 425);
            }
            $state['attempts']++;
            if (!$this->answerValid($state['type'], (array) $state['expected'], $answer)
                || !$this->workValid($state['challenge']['work'] ?? null, $workNonce)) {
                return ['state' => $state, 'error' => new CaptchaException('incorrect_answer', 422)];
            }
            $proof = ChallengeGenerator::token();
            $proofExpires = min($state['expiresMs'], $now + ($policy->proofTtlSeconds * 1000));
            $state['state'] = 'solved';
            $state['proofHash'] = hash('sha256', $proof);
            $state['proofExpiresMs'] = $proofExpires;
            $state['expected'] = null;
            $state['challenge'] = null;
            return ['state' => $state, 'result' => ['token' => $state['id'] . '.' . $proof, 'expiresInMs' => $proofExpires - $now]];
        });
    }

    public function consume(string $proofToken, string $action, string $binding): bool
    {
        $this->validateBinding($binding);
        if (!$this->validAction($action)) {
            throw new CaptchaException('invalid_proof', 403);
        }
        $parts = explode('.', $proofToken);
        if (count($parts) !== 2 || !preg_match('/^[a-f0-9]{64}$/D', $parts[0]) || !$this->validRawToken($parts[1])) {
            throw new CaptchaException('invalid_proof', 403);
        }
        $now = self::now();
        return $this->store->transaction($parts[0], 900, function (?array $state) use ($parts, $action, $binding, $now): array {
            if ($state === null || $state['state'] !== 'solved' || $state['action'] !== $action
                || !hash_equals($state['binding'], $this->bind($binding))
                || !hash_equals($state['proofHash'], hash('sha256', $parts[1]))
                || $now >= $state['proofExpiresMs'] || $now >= $state['expiresMs'] || !$this->policyActive($state)) {
                throw new CaptchaException('invalid_proof', 403);
            }
            $state['state'] = 'consumed';
            $state['proofHash'] = '';
            return ['state' => $state, 'result' => true];
        });
    }

    public function actionExists(string $action): bool
    {
        return $this->validAction($action) && (isset($this->actions[$action]) || $this->defaultPolicy !== null);
    }

    private function active(?array $state, string $binding, int $now): array
    {
        if ($state === null || !$this->policyActive($state) || $now >= $state['expiresMs'] || $now < $state['createdMs']
            || !hash_equals($state['binding'], $this->bind($binding))) {
            throw new CaptchaException('session_expired', 410);
        }
        return $state;
    }

    private function policyActive(array $state): bool
    {
        try {
            return $this->policy((string) $state['action'])->toArray() === $state['policy'];
        } catch (CaptchaException) {
            return false;
        }
    }

    private function policy(string $action): Policy
    {
        if (!$this->validAction($action)) {
            throw new CaptchaException('invalid_context', 400);
        }
        return $this->actions[$action] ?? $this->defaultPolicy ?? throw new CaptchaException('invalid_context', 400);
    }

    private function validAction(string $action): bool { return preg_match('/^[A-Za-z0-9._-]{1,64}$/D', $action) === 1; }
    private function bind(string $binding): string { return hash_hmac('sha256', "jervis.binding.v1\0{$binding}", $this->secret); }
    private function validateBinding(string $binding): void { if (strlen($binding) < 16 || strlen($binding) > 256) throw new CaptchaException('invalid_context', 400); }
    private function sessionId(string $token): string { if (!$this->validRawToken($token)) throw new CaptchaException('invalid_session', 400); return hash('sha256', $token); }
    private function validRawToken(string $token): bool { return strlen($token) === 43 && preg_match('/^[A-Za-z0-9_-]{43}$/D', $token) === 1; }
    private static function now(): int { return (int) floor(microtime(true) * 1000); }

    private function answerValid(string $type, array $want, array $got): bool
    {
        if ($type === 'pow') return true;
        if (in_array($type, ['text', 'audio', 'math', 'logic'], true)) {
            return hash_equals($this->normalize((string) ($want['text'] ?? '')), $this->normalize((string) ($got['text'] ?? '')));
        }
        if ($type === 'slider') return is_numeric($got['x'] ?? null) && abs((float) $want['x'] - (float) $got['x']) <= 5;
        if ($type === 'drag') return is_numeric($got['x'] ?? null) && is_numeric($got['y'] ?? null) && hypot((float) $want['x'] - (float) $got['x'], (float) $want['y'] - (float) $got['y']) <= 6;
        if ($type === 'rotate') {
            if (!is_numeric($got['angle'] ?? null)) return false;
            $angle = (float) $got['angle'];
            if ($angle < 0 || $angle >= 360) return false;
            $d = abs((float) $want['angle'] - $angle);
            return min($d, 360 - $d) <= 5;
        }
        if ($type === 'click') {
            $expected = $want['points'] ?? [];
            $received = $got['points'] ?? [];
            if (!is_array($received) || count($expected) !== count($received)) return false;
            foreach ($expected as $i => $point) {
                if (!isset($received[$i]['x'], $received[$i]['y'])
                    || hypot((float) $point['x'] - (float) $received[$i]['x'], (float) $point['y'] - (float) $received[$i]['y']) > 19) return false;
            }
            return true;
        }
        return false;
    }

    private function normalize(string $value): string
    {
        return strtoupper(trim(strtr($value, ['٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9','۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9'])));
    }

    private function workValid(?array $work, ?string $nonce): bool
    {
        if ($work === null) return ($nonce ?? '') === '';
        if ($nonce === null || preg_match('/^\d{1,8}$/D', $nonce) !== 1) return false;
        $bits = (int) ($work['bits'] ?? 0);
        if ($bits < 1 || $bits > 20) return false;
        $hash = hash('sha256', (string) $work['seed'] . ':' . $nonce, true);
        for ($i = 0; $i < $bits; $i++) {
            if ((ord($hash[intdiv($i, 8)]) & (1 << (7 - ($i % 8)))) !== 0) return false;
        }
        return true;
    }
}
