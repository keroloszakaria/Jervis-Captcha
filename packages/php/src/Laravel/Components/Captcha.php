<?php
declare(strict_types=1);
namespace Jervis\Captcha\Laravel\Components;
use Illuminate\Contracts\View\View;use Illuminate\View\Component;
final class Captcha extends Component
{
    public function __construct(public string $action,public string $locale='en',public string $theme='auto',public string $presentation='inline',public string $name='jervisCaptchaToken'){}
    public function render(): View{return view('jervis-captcha::captcha');}
}
