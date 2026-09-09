<?php
declare(strict_types=1);
namespace Jervis\Captcha\Laravel\Support;
use Illuminate\Http\Request;
use Jervis\Captcha\CaptchaException;
use Jervis\Captcha\Contracts\BindingResolver;
final class LaravelSessionBinding implements BindingResolver
{
    public function resolve(Request $request): string { if(!$request->hasSession())throw new CaptchaException('session_required',500);$id=(string)$request->session()->getId();if(strlen($id)<16)throw new CaptchaException('session_required',500);return $id; }
}
