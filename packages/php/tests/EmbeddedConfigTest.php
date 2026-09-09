<?php
declare(strict_types=1);

namespace Jervis\Captcha\Tests;

use InvalidArgumentException;
use Jervis\Captcha\Laravel\JervisCaptchaServiceProvider;
use Jervis\Captcha\Laravel\Support\EmbeddedConfig;
use Orchestra\Testbench\TestCase;

final class EmbeddedConfigTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [JervisCaptchaServiceProvider::class];
    }

    public function testDefaultEmbeddedRouteAndFieldConfigurationIsValid(): void
    {
        self::assertSame('jervisCaptchaToken', EmbeddedConfig::fieldName());
        self::assertSame('jervis-captcha', EmbeddedConfig::routePrefix());
        self::assertContains('web', EmbeddedConfig::routeMiddleware());
        self::assertTrue(EmbeddedConfig::validFieldName());
        self::assertTrue(EmbeddedConfig::validRouteConfiguration());
    }

    public function testNestedRoutePrefixIsSupportedButRootAndTraversalAreRejected(): void
    {
        $this->app['config']->set('jervis-captcha.routes.prefix', 'internal/security/captcha');
        self::assertSame('internal/security/captcha', EmbeddedConfig::routePrefix());

        foreach (['', '/', '../captcha', 'captcha/../admin', 'captcha?x=1', 'captcha//nested'] as $invalid) {
            $this->app['config']->set('jervis-captcha.routes.prefix', $invalid);
            try {
                EmbeddedConfig::routePrefix();
                self::fail("Invalid route prefix should be rejected: {$invalid}");
            } catch (InvalidArgumentException) {
                self::assertFalse(EmbeddedConfig::validRouteConfiguration());
            }
        }
    }

    public function testManagedRoutesCannotDropLaravelWebMiddleware(): void
    {
        $this->app['config']->set('jervis-captcha.routes.middleware', ['throttle:60,1']);
        $this->expectException(InvalidArgumentException::class);
        EmbeddedConfig::routeMiddleware();
    }

    public function testProofFieldNameIsBoundedAndPredictable(): void
    {
        $this->app['config']->set('jervis-captcha.field_name', 'captcha_proof-1');
        self::assertSame('captcha_proof-1', EmbeddedConfig::fieldName());

        foreach (['1captcha', 'captcha.proof', 'captcha proof', '', str_repeat('a', 65)] as $invalid) {
            $this->app['config']->set('jervis-captcha.field_name', $invalid);
            self::assertFalse(EmbeddedConfig::validFieldName());
        }
    }
}
