<?php
declare(strict_types=1);

namespace Jervis\Captcha\Laravel\Commands;

use Illuminate\Console\Command;
use Jervis\Captcha\Contracts\Store;
use Jervis\Captcha\Engine\EmbeddedEngine;
use Jervis\Captcha\Laravel\Support\RequestLimiter;

final class DoctorCommand extends Command
{
    protected $signature = 'jervis-captcha:doctor';
    protected $description = 'Validate embedded Jervis Captcha runtime requirements';

    public function handle(Store $store): int
    {
        $browserAsset = dirname(__DIR__, 3) . '/resources/dist/jervis-captcha.js';
        $audioAsset = dirname(__DIR__, 3) . '/resources/audio/digits.asset';
        $cacheStore = (string) (config('jervis-captcha.store') ?: config('cache.default', ''));
        $secret = (string) config('jervis-captcha.secret', '');
        $fieldName = (string) config('jervis-captcha.field_name', 'jervisCaptchaToken');
        $fieldNameReady = preg_match('/^[A-Za-z_][A-Za-z0-9_-]{0,63}$/D', $fieldName) === 1;

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
            'zlib extension' => function_exists('gzdecode'),
            'secure random' => function_exists('random_bytes'),
            'secret >= 32 bytes' => strlen($secret) >= 32,
            'proof field name' => $fieldNameReady,
            'engine configuration' => $engineReady,
            'request budgets' => RequestLimiter::configurationValid(),
            'persistent cache + lock' => $cacheReady,
            'browser asset' => is_file($browserAsset) && filesize($browserAsset) > 1000,
            'digit audio asset' => is_file($audioAsset) && filesize($audioAsset) > 10000,
        ];

        foreach ($checks as $label => $ok) {
            $this->line(sprintf('%-26s %s', $label, $ok ? 'OK' : 'FAIL'));
        }
        $this->line(sprintf('%-26s %s', 'cache store', $cacheStore !== '' ? $cacheStore : '(not configured)'));
        $this->line(sprintf('%-26s %s', 'proof field', $fieldNameReady ? $fieldName : '(invalid)'));

        if (!$fieldNameReady) {
            $this->warn('JERVIS_CAPTCHA_FIELD_NAME must start with a letter/underscore and contain only letters, numbers, underscore or hyphen (max 64 chars).');
        }
        if (!$cacheReady) {
            $this->warn('Use a persistent Laravel cache store with atomic locks. The array/null stores are not valid CAPTCHA state stores.');
        }
        if (!RequestLimiter::configurationValid()) {
            $this->warn('Request budgets must be integers between 1 and 100000 requests per minute.');
        }

        return in_array(false, $checks, true) ? self::FAILURE : self::SUCCESS;
    }
}
