<?php
declare(strict_types=1);

namespace Jervis\Captcha\Tests;

use Illuminate\Routing\Router;
use Jervis\Captcha\Laravel\JervisCaptchaServiceProvider;
use Orchestra\Testbench\TestCase;

final class AssetRouteTest extends TestCase
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
    }

    public function testBrowserAssetDoesNotOpenLaravelSessionButVerificationRoutesDo(): void
    {
        /** @var Router $router */
        $router = $this->app->make(Router::class);
        $asset = $router->getRoutes()->getByName('jervis-captcha.asset');
        $session = $router->getRoutes()->getByName('jervis-captcha.session');
        self::assertNotNull($asset);
        self::assertNotNull($session);
        self::assertNotContains('web', $asset->gatherMiddleware());
        self::assertContains('web', $session->gatherMiddleware());
    }

    public function testBrowserAssetUsesStrictLocalScriptHeaders(): void
    {
        $this->get('/jervis-captcha/assets/jervis-captcha.js')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/javascript; charset=utf-8')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Cross-Origin-Resource-Policy', 'same-origin');
    }
}
