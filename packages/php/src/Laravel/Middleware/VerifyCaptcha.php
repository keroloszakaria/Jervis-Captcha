<?php
declare(strict_types=1);

namespace Jervis\Captcha\Laravel\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Jervis\Captcha\CaptchaException;
use Jervis\Captcha\Contracts\BindingResolver;
use Jervis\Captcha\Engine\EmbeddedEngine;
use Jervis\Captcha\Laravel\Support\EmbeddedConfig;
use Symfony\Component\HttpFoundation\Response;

final readonly class VerifyCaptcha
{
    public function __construct(private EmbeddedEngine $engine, private BindingResolver $binding) {}

    public function handle(Request $request, Closure $next, string $action): Response
    {
        try {
            $field = EmbeddedConfig::fieldName();
        } catch (\Throwable $e) {
            report($e);
            return $this->unavailable($request, 'jervisCaptchaToken');
        }

        $token = (string) ($request->header('X-Jervis-Captcha') ?: $request->input($field, ''));
        try {
            if ($token === '' || !$this->engine->consume($token, $action, $this->binding->resolve($request))) {
                throw new CaptchaException('invalid_proof', 403);
            }
        } catch (CaptchaException $e) {
            if ($e->status >= 500) return $this->unavailable($request, $field);
            return $this->invalid($request, $field);
        } catch (\Throwable $e) {
            report($e);
            return $this->unavailable($request, $field);
        }

        return $next($request);
    }

    private function invalid(Request $request, string $field): Response
    {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'CAPTCHA verification failed',
                'errors' => [$field => ['CAPTCHA verification failed.']],
            ], 422);
        }
        throw ValidationException::withMessages([$field => 'CAPTCHA verification failed.']);
    }

    private function unavailable(Request $request, string $field): Response
    {
        return response()->json([
            'message' => 'CAPTCHA verification is temporarily unavailable',
            'errors' => [$field => ['CAPTCHA verification is temporarily unavailable.']],
        ], 503);
    }
}
