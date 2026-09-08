<?php
declare(strict_types=1);
namespace Jervis\Captcha\Laravel\Commands;
use Illuminate\Console\Command;
final class InstallCommand extends Command
{
    protected $signature='jervis-captcha:install';protected $description='Publish Jervis Captcha configuration for embedded Laravel mode';
    public function handle(): int{$this->call('vendor:publish',['--tag'=>'jervis-captcha-config','--force'=>false]);$this->info('Jervis Captcha embedded mode installed. Add actions to config/jervis-captcha.php and use jervis.captcha:<action> middleware.');return self::SUCCESS;}
}
