<?php
declare(strict_types=1);
namespace Jervis\Captcha\Laravel\Commands;
use Illuminate\Console\Command;
use Jervis\Captcha\Contracts\Store;
final class DoctorCommand extends Command
{
    protected $signature = 'jervis-captcha:doctor';
    protected $description = 'Validate embedded Jervis Captcha runtime requirements';
    public function handle(Store $store): int
    {
        $asset = dirname(__DIR__, 3) . '/resources/dist/jervis-captcha.js';
        $checks = [
            'PHP >= 8.2' => PHP_VERSION_ID >= 80200,
            'GD extension' => extension_loaded('gd'),
            'hash extension' => extension_loaded('hash'),
            'secure random' => function_exists('random_bytes'),
            'cache + atomic lock' => $store->ping(),
            'browser asset' => is_file($asset) && filesize($asset) > 0,
        ];
        foreach ($checks as $label => $ok) $this->line(sprintf('%-24s %s', $label, $ok ? 'OK' : 'FAIL'));
        return in_array(false, $checks, true) ? self::FAILURE : self::SUCCESS;
    }
}
