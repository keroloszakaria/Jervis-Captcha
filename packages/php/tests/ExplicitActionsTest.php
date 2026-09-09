<?php
declare(strict_types=1);

namespace Jervis\Captcha\Tests;

use Jervis\Captcha\Laravel\JervisCaptchaServiceProvider;
use Orchestra\Testbench\TestCase;

final class ExplicitActionsTest extends TestCase
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
        $app['config']->set('jervis-captcha.secret', str_repeat('s', 40));
        $app['config']->set('jervis-captcha.default_policy', null);
        $app['config']->set('jervis-captcha.actions', [
            'signup' => [
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
                'sessionsPerMinute' => 50,
            ],
        ]);
    }

    public function testNullDefaultPolicyKeepsUnknownActionsDenied(): void
    {
        $this->postJson('/jervis-captcha/session/login')
            ->assertNotFound()
            ->assertJson(['code' => 'invalid_context']);

        $this->postJson('/jervis-captcha/session/signup')
            ->assertOk()
            ->assertJson(['accessibilityAvailable' => false]);
    }
}
