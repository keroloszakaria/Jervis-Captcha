<?php
declare(strict_types=1);
namespace Jervis\Captcha\Contracts;
use Illuminate\Http\Request;
interface BindingResolver
{
    public function resolve(Request $request): string;
}
