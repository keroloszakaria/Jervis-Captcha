<?php
declare(strict_types=1);

namespace Jervis\Captcha\Tests;

use Illuminate\Http\Request;
use Jervis\Captcha\Laravel\Middleware\LimitCaptchaPayload;
use Orchestra\Testbench\TestCase;
use Symfony\Component\HttpFoundation\Response;

final class PayloadSizeMiddlewareTest extends TestCase
{
    public function testOversizedAndMalformedContentLengthAreRejectedBeforeControllerLogic(): void
    {
        $middleware = new LimitCaptchaPayload();

        foreach ([LimitCaptchaPayload::MAX_BYTES + 1, 'invalid', -1] as $length) {
            $request = Request::create('/jervis-captcha/v1/answers', 'POST', [], [], [], [
                'CONTENT_LENGTH' => (string) $length,
                'CONTENT_TYPE' => 'application/json',
            ]);
            $called = false;
            $response = $middleware->handle($request, function () use (&$called): Response {
                $called = true;
                return new Response('unexpected');
            });
            self::assertSame(413, $response->getStatusCode());
            self::assertFalse($called);
        }
    }

    public function testBoundedOrMissingContentLengthContinues(): void
    {
        $middleware = new LimitCaptchaPayload();
        foreach ([(string) LimitCaptchaPayload::MAX_BYTES, null] as $length) {
            $server = ['CONTENT_TYPE' => 'application/json'];
            if ($length !== null) $server['CONTENT_LENGTH'] = $length;
            $request = Request::create('/jervis-captcha/v1/answers', 'POST', [], [], [], $server, '{}');
            if ($length === null) $request->server->remove('CONTENT_LENGTH');
            $response = $middleware->handle($request, static fn (): Response => new Response('ok', 200));
            self::assertSame(200, $response->getStatusCode());
        }
    }

    public function testOversizedBodyWithoutContentLengthIsRejected(): void
    {
        $middleware = new LimitCaptchaPayload();
        $request = Request::create(
            '/jervis-captcha/v1/answers',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            str_repeat('x', LimitCaptchaPayload::MAX_BYTES + 1),
        );
        $request->server->remove('CONTENT_LENGTH');
        $called = false;
        $response = $middleware->handle($request, function () use (&$called): Response {
            $called = true;
            return new Response('unexpected');
        });
        self::assertSame(413, $response->getStatusCode());
        self::assertFalse($called);
    }
}
