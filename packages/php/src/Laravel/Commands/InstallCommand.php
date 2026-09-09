<?php
declare(strict_types=1);

namespace Jervis\Captcha\Laravel\Commands;

use Illuminate\Console\Command;

final class InstallCommand extends Command
{
    protected $signature = 'jervis-captcha:install {--skip-doctor : Publish configuration without running runtime readiness checks}';
    protected $description = 'Install Jervis Captcha embedded Laravel configuration';

    public function handle(): int
    {
        $this->call('vendor:publish', ['--tag' => 'jervis-captcha-config', '--force' => false]);
        $this->info('Jervis Captcha embedded mode installed. No separate engine service is required.');
        $this->line('Blade: <x-jervis-captcha action="signup" />');
        $this->line("Route middleware: ->middleware('jervis.captcha:signup')");
        $this->line('Optional dedicated base secret: php artisan jervis-captcha:key');

        if ((bool) $this->option('skip-doctor')) {
            $this->warn('Runtime readiness checks were skipped. Run php artisan jervis-captcha:doctor before deployment.');
            return self::SUCCESS;
        }

        $result = $this->call('jervis-captcha:doctor');
        if ($result !== self::SUCCESS) {
            $this->error('Jervis Captcha configuration was published, but the embedded runtime is not ready. Fix the failed checks above.');
            return self::FAILURE;
        }

        $this->info('Jervis Captcha is ready for embedded Laravel use.');
        return self::SUCCESS;
    }
}
