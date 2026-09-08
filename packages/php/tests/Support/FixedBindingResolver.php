<?php
declare(strict_types=1);

namespace Jervis\Captcha\Tests\Support;

use Illuminate\Http\Request;
use Jervis\Captcha\Contracts\BindingResolver;

final class FixedBindingResolver implements BindingResolver
{
    public function resolve(Request $request): string
    {
        return 'jervis-test-binding-0123456789abcdef';
    }
}
