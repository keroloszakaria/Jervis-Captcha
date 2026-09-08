<?php
declare(strict_types=1);

namespace Jervis\Captcha\Tests;

use Jervis\Captcha\CaptchaException;
use Jervis\Captcha\Engine\EmbeddedEngine;
use Jervis\Captcha\Engine\MemoryStore;
use PHPUnit\Framework\TestCase;

final class EngineTest extends TestCase
{
    private function engine(string $type = 'pow'): EmbeddedEngine
    {
        return new EmbeddedEngine(new MemoryStore(), str_repeat('s', 40), [], [
            'types' => [$type], 'isAntiAI' => false,
            'workBits' => $type === 'pow' ? 12 : 0, 'minSolveMs' => 0, 'sessionTtlSeconds' => 60,
            'challengeTtlSeconds' => 30, 'proofTtlSeconds' => 30, 'maxAttempts' => 3,
            'maxRefreshes' => 3, 'sessionsPerMinute' => 50,
        ]);
    }

    public function testDefaultPolicySupportsApplicationOwnedActionNames(): void
    {
        $engine = $this->engine();
        self::assertTrue($engine->actionExists('signup')); self::assertTrue($engine->actionExists('password.reset'));
        self::assertFalse($engine->actionExists('../admin')); self::assertFalse($engine->actionExists(''));
    }

    public function testPowProofIsOneUseAndBindingScoped(): void
    {
        $engine = $this->engine('pow'); $binding = str_repeat('b', 20);
        $session = $engine->createSession('signup', $binding); $challenge = $engine->issue($session['sessionToken'], $binding);
        $nonce = $this->solveWork($challenge['work']['seed'], $challenge['work']['bits']);
        $proof = $engine->solve($session['sessionToken'], $binding, $challenge['id'], [], $nonce);
        self::assertTrue($engine->consume($proof['token'], 'signup', $binding));
        $this->expectException(CaptchaException::class); $engine->consume($proof['token'], 'signup', $binding);
    }

    public function testWrongBindingCannotIssueChallenge(): void
    {
        $engine = $this->engine('pow'); $session = $engine->createSession('signup', str_repeat('a', 20));
        $this->expectException(CaptchaException::class); $engine->issue($session['sessionToken'], str_repeat('z', 20));
    }

    public function testProofCannotCrossActions(): void
    {
        $engine = $this->engine('pow'); $binding = str_repeat('b', 20);
        $session = $engine->createSession('signup', $binding); $challenge = $engine->issue($session['sessionToken'], $binding);
        $proof = $engine->solve($session['sessionToken'], $binding, $challenge['id'], [], $this->solveWork($challenge['work']['seed'], $challenge['work']['bits']));
        $this->expectException(CaptchaException::class);
        $engine->consume($proof['token'], 'login', $binding);
    }

    public function testIncorrectWorkPersistsAttemptBudget(): void
    {
        $engine = $this->engine('pow'); $binding = str_repeat('b', 20);
        $session = $engine->createSession('signup', $binding); $challenge = $engine->issue($session['sessionToken'], $binding);
        for ($i = 0; $i < 3; $i++) {
            try { $engine->solve($session['sessionToken'], $binding, $challenge['id'], [], '0'); } catch (CaptchaException $e) { self::assertSame('incorrect_answer', $e->errorCode); }
        }
        try { $engine->solve($session['sessionToken'], $binding, $challenge['id'], [], '0'); self::fail('attempt budget should lock'); }
        catch (CaptchaException $e) { self::assertSame('session_locked', $e->errorCode); }
    }

    public function testGlobalIssuanceBudgetCannotBeBypassedWithActionNames(): void
    {
        $policy = ['types'=>['pow'],'isAntiAI'=>false,'workBits'=>12,'minSolveMs'=>0,'sessionTtlSeconds'=>60,'challengeTtlSeconds'=>30,'proofTtlSeconds'=>30,'maxAttempts'=>3,'maxRefreshes'=>3,'sessionsPerMinute'=>50];
        $engine = new EmbeddedEngine(new MemoryStore(), str_repeat('s', 40), [], $policy, null, 2);
        $engine->createSession('a', str_repeat('a', 20)); $engine->createSession('b', str_repeat('b', 20));
        try { $engine->createSession('c', str_repeat('c', 20)); self::fail('global rate limit should apply across actions'); }
        catch (CaptchaException $e) { self::assertSame('rate_limited', $e->errorCode); }
    }

    public function testPolicyChangeInvalidatesOutstandingSession(): void
    {
        $store = new MemoryStore(); $secret = str_repeat('s', 40); $binding = str_repeat('b', 20);
        $base = ['types'=>['pow'],'isAntiAI'=>false,'workBits'=>12,'minSolveMs'=>0,'sessionTtlSeconds'=>60,'challengeTtlSeconds'=>30,'proofTtlSeconds'=>30,'maxAttempts'=>3,'maxRefreshes'=>3,'sessionsPerMinute'=>50];
        $first = new EmbeddedEngine($store, $secret, [], $base); $session = $first->createSession('signup', $binding);
        $base['proofTtlSeconds'] = 29; $changed = new EmbeddedEngine($store, $secret, [], $base);
        try { $changed->issue($session['sessionToken'], $binding); self::fail('policy changes must revoke outstanding state'); }
        catch (CaptchaException $e) { self::assertSame('session_expired', $e->errorCode); }
    }

    private function solveWork(string $seed, int $bits): string
    {
        for ($i = 0; $i < 10000000; $i++) {
            $hash = hash('sha256', $seed . ':' . $i, true); $valid = true;
            for ($bit = 0; $bit < $bits; $bit++) {
                if ((ord($hash[intdiv($bit, 8)]) & (1 << (7 - ($bit % 8)))) !== 0) { $valid = false; break; }
            }
            if ($valid) return (string) $i;
        }
        throw new \RuntimeException('work not solved');
    }
}
