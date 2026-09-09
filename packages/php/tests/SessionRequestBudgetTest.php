<?php
declare(strict_types=1);

namespace Jervis\Captcha\Tests;

use Jervis\Captcha\Laravel\JervisCaptchaServiceProvider;
use Jervis\Captcha\Laravel\Support\RequestLimiter;
use Jervis\Captcha\Tests\Support\FixedBindingResolver;
use Orchestra\Testbench\TestCase;

final class SessionRequestBudgetTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [JervisCaptchaServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('k', 32)));
        $app['config']->set('cache.default', 'array');
        $app['config']->set('session.driver', 'array');
        $app['config']->set('jervis-captcha.store', 'array');
        $app['config']->set('jervis-captcha.binding_resolver', FixedBindingResolver::class);
        $app['config']->set('jervis-captcha.secret', str_repeat('s', 40));
        $app['config']->set('jervis-captcha.global_sessions_per_minute', 1000);
        $app['config']->set('jervis-captcha.default_policy', [
            'types' => ['pow'],
            'accessibilityTypes' => [],
            'isAntiAI' => false,
            'workBits' => 12,
            'minSolveMs' => 0,
            'sessionTtlSeconds' => 60,
            'challengeTtlSeconds' => 30,
            'proofTtlSeconds' => 30,
            'maxAttempts' => 3,
            'maxRefreshes' => 3,
            'sessionsPerMinute' => 100,
        ]);
        $app['config']->set('jervis-captcha.request_limits', [
            'global_per_minute' => 100,
            'session_per_binding_per_minute' => 2,
            'challenge_per_binding_per_minute' => 100,
            'answer_per_binding_per_minute' => 100,
        ]);
    }

    public function testManagedSessionBudgetCannotBeBypassedByRotatingActions(): void
    {
        $this->postJson('/jervis-captcha/session/action-a')->assertOk();
        $this->postJson('/jervis-captcha/session/action-b')->assertOk();
        $this->postJson('/jervis-captcha/session/action-c')
            ->assertStatus(429)
            ->assertJson(['code' => 'rate_limited']);
    }

    public function testRequestBudgetConfigurationDoesNotSilentlyCoerceInvalidTypes(): void
    {
        $this->app['config']->set('jervis-captcha.request_limits.session_per_binding_per_minute', '12');
        self::assertFalse(RequestLimiter::configurationValid());

        $this->postJson('/jervis-captcha/session/signup')
            ->assertStatus(503)
            ->assertJson(['code' => 'verification_unavailable']);
    }
}
