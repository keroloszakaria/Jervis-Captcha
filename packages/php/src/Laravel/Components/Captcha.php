<?php
declare(strict_types=1);

namespace Jervis\Captcha\Laravel\Components;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;
use Jervis\Captcha\Laravel\Support\EmbeddedConfig;

final class Captcha extends Component
{
    public readonly string $name;

    public function __construct(
        public string $action,
        public string $locale = 'en',
        public string $theme = 'auto',
        public string $presentation = 'inline',
    ) {
        $this->name = EmbeddedConfig::fieldName();
    }

    public function render(): View
    {
        return view('jervis-captcha::captcha');
    }
}
