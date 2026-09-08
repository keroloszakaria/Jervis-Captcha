<?php
declare(strict_types=1);

namespace Jervis\Captcha\Laravel;

use Illuminate\Cache\CacheManager;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Jervis\Captcha\Contracts\BindingResolver;
use Jervis\Captcha\Contracts\Store;
use Jervis\Captcha\Engine\EmbeddedEngine;
use Jervis\Captcha\Laravel\Commands\DoctorCommand;
use Jervis\Captcha\Laravel\Commands\InstallCommand;
use Jervis\Captcha\Laravel\Components\Captcha;
use Jervis\Captcha\Laravel\Controllers\AssetController;
use Jervis\Captcha\Laravel\Controllers\CaptchaController;
use Jervis\Captcha\Laravel\Middleware\VerifyCaptcha;
use Jervis\Captcha\Laravel\Storage\LaravelCacheStore;
use Jervis\Captcha\Laravel\Support\LaravelSessionBinding;

final class JervisCaptchaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(dirname(__DIR__, 2) . '/config/jervis-captcha.php', 'jervis-captcha');
        $this->app->singleton(Store::class, fn ($app) => new LaravelCacheStore($app->make(CacheManager::class), config('jervis-captcha.store'), (string) config('jervis-captcha.cache_prefix', 'jervis-captcha:')));
        $this->app->singleton(BindingResolver::class, fn ($app) => $app->make((string) config('jervis-captcha.binding_resolver', LaravelSessionBinding::class)));
        $this->app->singleton(EmbeddedEngine::class, fn ($app) => new EmbeddedEngine($app->make(Store::class), (string) config('jervis-captcha.secret'), (array) config('jervis-captcha.actions', []), (array) config('jervis-captcha.default_policy', []), null, (int) config('jervis-captcha.global_sessions_per_minute', 300)));
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
        $prefix = trim((string) config('jervis-captcha.routes.prefix', 'jervis-captcha'), '/');
        $middleware = (array) config('jervis-captcha.routes.middleware', ['web']);
        Route::prefix($prefix)->middleware($middleware)->group(function (): void {
            Route::post('/session/{action}', [CaptchaController::class, 'session'])->where('action', '[A-Za-z0-9._-]{1,64}')->name('jervis-captcha.session');
            Route::post('/v1/challenges', [CaptchaController::class, 'challenge'])->name('jervis-captcha.challenge');
            Route::post('/v1/answers', [CaptchaController::class, 'answer'])->name('jervis-captcha.answer');
            Route::get('/assets/{file}', AssetController::class)
                ->where('file', 'jervis-captcha(?:-base)?\\.js')
                ->name('jervis-captcha.asset');
        });
    }
}
