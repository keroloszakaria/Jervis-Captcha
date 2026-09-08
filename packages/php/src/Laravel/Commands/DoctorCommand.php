<?php
declare(strict_types=1);

namespace Jervis\Captcha\Laravel\Commands;

use Illuminate\Console\Command;
use Jervis\Captcha\Contracts\Store;
use Jervis\Captcha\Engine\EmbeddedEngine;

final class DoctorCommand extends Command
{
    protected $signature = 'jervis-captcha:doctor';
    protected $description = 'Validate embedded Jervis Captcha runtime requirements';

    public function handle(Store $store): int
    {
        $asset = dirname(__DIR__, 3) . '/resources/dist/jervis-captcha.js';
        $cacheStore = (string) (config('jervis-captcha.store') ?: config('cache.default', ''));
        $secret = (string) config('jervis-captcha.secret', '');

        $engineReady = false;
        try {
            app(EmbeddedEngine::class);
            $engineReady = true;
        } catch (\Throwable $e) {
            report($e);
        }

        $cacheReady = false;
        try {
            $cacheReady = !in_array(strtolower($cacheStore), ['', 'array', 'null'], true) && $store->ping();
        } catch (\Throwable $e) {
            report($e);
        }

        $checks = [
            'PHP >= 8.2' => PHP_VERSION_ID >= 80200,
            'GD extension' => extension_loaded('gd'),
            'hash extension' => extension_loaded('hash'),
            'secure random' => function_exists('random_bytes'),
            'secret >= 32 bytes' => strlen($secret) >= 32,
            'engine configuration' => $engineReady,
            'persistent cache + lock' => $cacheReady,
            'browser asset' => is_file($asset) && filesize($asset) > 1000,
        ];

        foreach ($checks as $label => $ok) {
            $this->line(sprintf('%-26s %s', $label, $ok ? 'OK' : 'FAIL'));
        }
        $this->line(sprintf('%-26s %s', 'cache store', $cacheStore !== '' ? $cacheStore : '(not configured)'));

        if (!$cacheReady) {
            $this->warn('Use a persistent Laravel cache store with atomic locks. The array/null stores are not valid CAPTCHA state stores.');
        }

        return in_array(false, $checks, true) ? self::FAILURE : self::SUCCESS;
    }
}
