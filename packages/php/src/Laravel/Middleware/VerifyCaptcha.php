<?php
declare(strict_types=1);

namespace Jervis\Captcha\Laravel\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Jervis\Captcha\CaptchaException;
use Jervis\Captcha\Contracts\BindingResolver;
use Jervis\Captcha\Engine\EmbeddedEngine;
use Symfony\Component\HttpFoundation\Response;

final readonly class VerifyCaptcha
{
    public function __construct(private EmbeddedEngine $engine, private BindingResolver $binding) {}

    public function handle(Request $request, Closure $next, string $action): Response
    {
        $field = (string) config('jervis-captcha.field_name', 'jervisCaptchaToken');
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_-]{0,63}$/D', $field) !== 1) {
            return $this->reject($request, 'jervisCaptchaToken', new CaptchaException('verification_unavailable', 503));
        }

        $token = (string) ($request->header('X-Jervis-Captcha') ?: $request->input($field, ''));
        try {
            if ($token === '' || !$this->engine->consume($token, $action, $this->binding->resolve($request))) {
                throw new CaptchaException('invalid_proof', 403);
            }
        } catch (\Throwable $e) {
            return $this->reject($request, $field, $e);
        }

        return $next($request);
    }

    private function reject(Request $request, string $field, \Throwable $error): Response
    {
        if (!$error instanceof CaptchaException) report($error);
        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'CAPTCHA verification failed',
                'errors' => [$field => ['CAPTCHA verification failed.']],
            ], 422);
        }
        throw ValidationException::withMessages([$field => 'CAPTCHA verification failed.']);
    }
}
