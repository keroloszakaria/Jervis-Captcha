<?php
declare(strict_types=1);

namespace Jervis\Captcha\Tests;

use Illuminate\Http\Request;
use Jervis\Captcha\CaptchaException;
use Jervis\Captcha\Laravel\Support\LaravelSessionBinding;
use Orchestra\Testbench\TestCase;

final class LaravelSessionBindingTest extends TestCase
{
    public function testStartedLaravelSessionProvidesTheTrustedBinding(): void
    {
        $session = $this->app->make('session')->driver();
        $session->setId('0123456789abcdef0123456789abcdef');
        $session->start();

        $request = Request::create('/protected', 'POST');
        $request->setLaravelSession($session);

        $binding = (new LaravelSessionBinding())->resolve($request);
        self::assertSame($session->getId(), $binding);
        self::assertGreaterThanOrEqual(16, strlen($binding));
    }

    public function testMissingLaravelSessionFailsClosed(): void
    {
        $request = Request::create('/protected', 'POST');

        try {
            (new LaravelSessionBinding())->resolve($request);
            self::fail('Missing Laravel session must fail closed');
        } catch (CaptchaException $e) {
            self::assertSame('session_required', $e->errorCode);
            self::assertSame(500, $e->status);
        }
    }
}
