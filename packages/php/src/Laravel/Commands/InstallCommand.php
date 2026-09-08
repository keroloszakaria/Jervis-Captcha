<?php
declare(strict_types=1);
namespace Jervis\Captcha\Laravel\Commands;
use Illuminate\Console\Command;
final class InstallCommand extends Command
{
    protected $signature = 'jervis-captcha:install';
    protected $description = 'Install Jervis Captcha embedded Laravel configuration';
    public function handle(): int
    {
        $this->call('vendor:publish', ['--tag' => 'jervis-captcha-config', '--force' => false]);
        $this->info('Jervis Captcha embedded mode installed. Use <x-jervis-captcha action="signup" /> and jervis.captcha:signup; no separate engine service is required.');
        return self::SUCCESS;
    }
}
