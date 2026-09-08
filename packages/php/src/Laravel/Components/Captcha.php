<?php
declare(strict_types=1);

namespace Jervis\Captcha\Laravel\Components;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;
use InvalidArgumentException;

final class Captcha extends Component
{
    public readonly string $name;

    public function __construct(
        public string $action,
        public string $locale = 'en',
        public string $theme = 'auto',
        public string $presentation = 'inline',
    ) {
        $field = (string) config('jervis-captcha.field_name', 'jervisCaptchaToken');
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_-]{0,63}$/D', $field) !== 1) {
            throw new InvalidArgumentException('Invalid Jervis Captcha field_name configuration');
        }
        $this->name = $field;
    }

    public function render(): View
    {
        return view('jervis-captcha::captcha');
    }
}
