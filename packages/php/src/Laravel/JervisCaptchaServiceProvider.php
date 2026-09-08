<?php
declare(strict_types=1);

namespace Jervis\Captcha\Laravel;

use Illuminate\Cache\CacheManager;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Jervis\Captcha\Contracts\BindingResolver;
use Jervis\Captcha\Contracts\Store;
use Jervis\Captcha\Engine\EmbeddedEngine;
use Jervis\Captcha\Laravel\Commands\DoctorCommand;
use Jervis\Captcha\Laravel\Commands\InstallCommand;
use Jervis\Captcha\Laravel\Components\Captcha;
use Jervis\Captcha\Laravel\Controllers\AssetController;
use Jervis\Captcha\Laravel\Controllers\CaptchaController;
use Jervis\Captcha\Laravel\Middleware\LimitCaptchaPayload;
use Jervis\Captcha\Laravel\Middleware\VerifyCaptcha;
use Jervis\Captcha\Laravel\Storage\LaravelCacheStore;
use Jervis\Captcha\Laravel\Support\EmbeddedConfig;
use Jervis\Captcha\Laravel\Support\LaravelSessionBinding;

final class JervisCaptchaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(dirname(__DIR__, 2) . '/config/jervis-captcha.php', 'jervis-captcha');
        $this->app->singleton(Store::class, fn ($app) => new LaravelCacheStore(
            $app->make(CacheManager::class),
            config('jervis-captcha.store'),
            (string) config('jervis-captcha.cache_prefix', 'jervis-captcha:'),
        ));
        $this->app->singleton(BindingResolver::class, fn ($app) => $app->make(
            (string) config('jervis-captcha.binding_resolver', LaravelSessionBinding::class),
        ));
        $this->app->singleton(EmbeddedEngine::class, function ($app): EmbeddedEngine {
            $actions = config('jervis-captcha.actions', []);
            $defaultPolicy = config('jervis-captcha.default_policy');

            if (!is_array($actions)) {
                throw new InvalidArgumentException('jervis-captcha.actions must be an array');
            }
            if ($defaultPolicy !== null && !is_array($defaultPolicy)) {
                throw new InvalidArgumentException('jervis-captcha.default_policy must be an array or null');
            }

            return new EmbeddedEngine(
                $app->make(Store::class),
                (string) config('jervis-captcha.secret'),
                $actions,
                $defaultPolicy,
                null,
                (int) config('jervis-captcha.global_sessions_per_minute', 300),
            );
        });
    }

    public function boot(Router $router): void
    {
        $router->aliasMiddleware('jervis.captcha', VerifyCaptcha::class);
        Blade::component('jervis-captcha', Captcha::class);
        $this->loadViewsFrom(dirname(__DIR__, 2) . '/resources/views', 'jervis-captcha');
        $this->publishes([dirname(__DIR__, 2) . '/config/jervis-captcha.php' => config_path('jervis-captcha.php')], 'jervis-captcha-config');
        if ($this->app->runningInConsole()) $this->commands([InstallCommand::class, DoctorCommand::class]);
        if ((bool) config('jervis-captcha.routes.enabled', true)) $this->routes();
    }

    private function routes(): void
    {
        $prefix = EmbeddedConfig::routePrefix();
        $middleware = EmbeddedConfig::routeMiddleware();

        // Browser runtime files are public local assets and do not need to open a
        // Laravel session. Verification routes remain under the required `web`
        // middleware so session binding and CSRF protection are preserved.
        Route::prefix($prefix)->group(function () use ($middleware): void {
            Route::get('/assets/{file}', AssetController::class)
                ->where('file', 'jervis-captcha(?:-base)?\\.js')
                ->name('jervis-captcha.asset');

            $verificationMiddleware = array_values(array_unique([LimitCaptchaPayload::class, ...$middleware]));
            Route::middleware($verificationMiddleware)->group(function (): void {
                Route::post('/session/{action}', [CaptchaController::class, 'session'])->where('action', '[A-Za-z0-9._-]{1,64}')->name('jervis-captcha.session');
                Route::post('/v1/challenges', [CaptchaController::class, 'challenge'])->name('jervis-captcha.challenge');
                Route::post('/v1/answers', [CaptchaController::class, 'answer'])->name('jervis-captcha.answer');
            });
        });
    }
}
