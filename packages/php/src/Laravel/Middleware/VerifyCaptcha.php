<?php
declare(strict_types=1);
namespace Jervis\Captcha\Laravel\Middleware;
use Closure;use Illuminate\Http\Request;use Illuminate\Validation\ValidationException;use Jervis\Captcha\CaptchaException;use Jervis\Captcha\Contracts\BindingResolver;use Jervis\Captcha\Engine\EmbeddedEngine;use Symfony\Component\HttpFoundation\Response;
final readonly class VerifyCaptcha
{
    public function __construct(private EmbeddedEngine $engine,private BindingResolver $binding){}
    public function handle(Request $request,Closure $next,string $action): Response { $token=(string)($request->header('X-Jervis-Captcha')?:$request->input('jervisCaptchaToken',''));try{if($token===''||!$this->engine->consume($token,$action,$this->binding->resolve($request)))throw new CaptchaException('invalid_proof',403);}catch(\Throwable){if($request->expectsJson())return response()->json(['message'=>'CAPTCHA verification failed','errors'=>['jervisCaptchaToken'=>['CAPTCHA verification failed.']]],422);throw ValidationException::withMessages(['jervisCaptchaToken'=>'CAPTCHA verification failed.']);}return $next($request); }
}
