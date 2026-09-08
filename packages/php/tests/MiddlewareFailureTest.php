<?php
declare(strict_types=1);

namespace Jervis\Captcha\Tests;

use Illuminate\Http\Request;
use Jervis\Captcha\CaptchaException;
use Jervis\Captcha\Contracts\BindingResolver;
use Jervis\Captcha\Laravel\JervisCaptchaServiceProvider;
use Orchestra\Testbench\TestCase;

final class MiddlewareFailureTest extends TestCase
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
            'sessionsPerMinute' => 50,
        ]);
    }

    protected function defineRoutes($router): void
    {
        $router->middleware(['web', 'jervis.captcha:signup'])
            ->post('/protected-failure-test', static fn () => response()->json(['accepted' => true]));
    }

    public function testInvalidProofIsAValidationFailure(): void
    {
        $this->postJson('/protected-failure-test', ['jervisCaptchaToken' => 'invalid'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.jervisCaptchaToken.0', 'CAPTCHA verification failed.');
    }

    public function testInvalidFieldConfigurationFailsClosedAsUnavailable(): void
    {
        $this->app['config']->set('jervis-captcha.field_name', 'invalid.field');
        $this->postJson('/protected-failure-test', ['jervisCaptchaToken' => 'anything'])
            ->assertStatus(503)
            ->assertJsonPath('errors.jervisCaptchaToken.0', 'CAPTCHA verification is temporarily unavailable.');
    }

    public function testBindingInfrastructureFailureReturnsUnavailable(): void
    {
        $this->app->instance(BindingResolver::class, new class implements BindingResolver {
            public function resolve(Request $request): string
            {
                throw new CaptchaException('session_required', 500);
            }
        });

        $this->postJson('/protected-failure-test', ['jervisCaptchaToken' => 'anything'])
            ->assertStatus(503)
            ->assertJsonPath('errors.jervisCaptchaToken.0', 'CAPTCHA verification is temporarily unavailable.');
    }

    public function testMalformedTrustedBindingIsUnavailableNotAUserValidationError(): void
    {
        $this->app->instance(BindingResolver::class, new class implements BindingResolver {
            public function resolve(Request $request): string
            {
                return 'short';
            }
        });

        $this->postJson('/protected-failure-test', ['jervisCaptchaToken' => str_repeat('a', 64) . '.' . str_repeat('b', 43)])
            ->assertStatus(503)
            ->assertJsonPath('errors.jervisCaptchaToken.0', 'CAPTCHA verification is temporarily unavailable.');
    }
}
