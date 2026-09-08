<?php
declare(strict_types=1);

namespace Jervis\Captcha\Tests;

use Jervis\Captcha\Laravel\JervisCaptchaServiceProvider;
use Orchestra\Testbench\TestCase;

final class PayloadValidationTest extends TestCase
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

    public function testAnswerPayloadSchemaAndSizesAreBoundedBeforeVerification(): void
    {
        $base = [
            'sessionToken' => str_repeat('a', 43),
            'challengeId' => str_repeat('b', 43),
        ];

        foreach ([
            ['answer' => ['text' => str_repeat('x', 129)]],
            ['answer' => ['unexpected' => 'value']],
            ['answer' => ['points' => [
                ['x' => 1, 'y' => 1], ['x' => 2, 'y' => 2],
                ['x' => 3, 'y' => 3], ['x' => 4, 'y' => 4],
            ]]],
            ['answer' => ['points' => [['x' => 1]]]],
            ['answer' => [], 'workNonce' => '123456789'],
            ['answer' => ['x' => 10001]],
        ] as $invalid) {
            $this->postJson('/jervis-captcha/v1/answers', array_replace($base, $invalid))
                ->assertUnprocessable();
        }
    }
}
