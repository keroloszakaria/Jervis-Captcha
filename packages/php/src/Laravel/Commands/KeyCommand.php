<?php
declare(strict_types=1);

namespace Jervis\Captcha\Laravel\Commands;

use Illuminate\Console\Command;
use Jervis\Captcha\Laravel\Support\EnvironmentSecretWriter;
use RuntimeException;

final class KeyCommand extends Command
{
    protected $signature = 'jervis-captcha:key {--force : Rotate an existing dedicated Jervis Captcha secret}';
    protected $description = 'Generate an optional dedicated Jervis Captcha secret in Laravel .env';

    public function handle(EnvironmentSecretWriter $writer): int
    {
        $path = $this->laravel->environmentFilePath();
        $configuredBefore = (string) config('jervis-captcha.secret', '');
        $contents = is_file($path) ? (string) file_get_contents($path) : '';
        $hasEnvLine = preg_match('/^[ \t]*JERVIS_CAPTCHA_SECRET[ \t]*=/m', $contents) === 1;
        $external = (string) env('JERVIS_CAPTCHA_SECRET', '');

        if (!$hasEnvLine && $external !== '') {
            if ((bool) $this->option('force')) {
                $this->error('JERVIS_CAPTCHA_SECRET is supplied outside .env. Rotate it in the deployment secret manager instead.');
                return self::FAILURE;
            }
            $this->info('A dedicated Jervis Captcha secret is already supplied by the deployment environment; .env was not changed.');
            return self::SUCCESS;
        }

        try {
            $result = $writer->write($path, (bool) $this->option('force'));
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        $configurationChanged = $configuredBefore === '' || !hash_equals($configuredBefore, $result['secret']);
        config()->set('jervis-captcha.secret', $result['secret']);
        if ($result['rotated']) {
            $this->warn('Dedicated Jervis Captcha secret rotated. Existing CAPTCHA sessions and proofs are intentionally invalidated.');
        } elseif ($result['created']) {
            $this->info('Dedicated Jervis Captcha secret generated and stored in .env.');
        } else {
            $this->info('Dedicated Jervis Captcha secret already exists; no change was made.');
        }

        if ($this->laravel->configurationIsCached() && ($result['created'] || $result['rotated'] || $configurationChanged)) {
            $this->call('config:clear');
            $this->warn('Laravel configuration cache was cleared so the new secret can take effect. Rebuild it with php artisan config:cache when appropriate.');
        }

        return self::SUCCESS;
    }
}
