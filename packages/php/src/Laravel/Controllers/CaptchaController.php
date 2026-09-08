<?php
declare(strict_types=1);

namespace Jervis\Captcha\Laravel\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Jervis\Captcha\CaptchaException;
use Jervis\Captcha\Contracts\BindingResolver;
use Jervis\Captcha\Engine\EmbeddedEngine;
use Jervis\Captcha\Laravel\Support\RequestLimiter;

final class CaptchaController extends Controller
{
    public function session(Request $request, string $action, EmbeddedEngine $engine, BindingResolver $binding): JsonResponse
    {
        if (!$engine->actionExists($action)) return response()->json(['code' => 'invalid_context'], 404);
        $request->validate([
            'variant' => ['sometimes', 'string', 'in:standard,accessible'],
            'type' => ['prohibited'],
            'policy' => ['prohibited'],
            'binding' => ['prohibited'],
            'isAntiAI' => ['prohibited'],
        ]);
        return $this->run(fn () => $engine->createSession(
            $action,
            $binding->resolve($request),
            (string) $request->input('variant', 'standard'),
        ));
    }

    public function challenge(Request $request, EmbeddedEngine $engine, BindingResolver $binding, RequestLimiter $limiter): JsonResponse
    {
        $request->validate(['sessionToken' => ['required', 'string', 'size:43']]);
        return $this->run(function () use ($request, $engine, $binding, $limiter): array {
            $resolvedBinding = $binding->resolve($request);
            $limiter->assertAllowed('challenge', $resolvedBinding);
            return $engine->issue((string) $request->input('sessionToken'), $resolvedBinding);
        });
    }

    public function answer(Request $request, EmbeddedEngine $engine, BindingResolver $binding, RequestLimiter $limiter): JsonResponse
    {
        $request->validate([
            'sessionToken' => ['required', 'string', 'size:43'],
            'challengeId' => ['required', 'string', 'size:43'],
            'answer' => ['required', 'array:text,x,y,angle,points', 'max:5'],
            'answer.text' => ['nullable', 'string', 'max:128'],
            'answer.x' => ['nullable', 'numeric', 'between:-10000,10000'],
            'answer.y' => ['nullable', 'numeric', 'between:-10000,10000'],
            'answer.angle' => ['nullable', 'numeric', 'between:-10000,10000'],
            'answer.points' => ['nullable', 'array', 'max:3'],
            'answer.points.*' => ['array:x,y'],
            'answer.points.*.x' => ['required', 'numeric', 'between:-10000,10000'],
            'answer.points.*.y' => ['required', 'numeric', 'between:-10000,10000'],
            'workNonce' => ['nullable', 'string', 'regex:/^\d{1,8}$/D'],
        ]);
        return $this->run(function () use ($request, $engine, $binding, $limiter): array {
            $resolvedBinding = $binding->resolve($request);
            $limiter->assertAllowed('answer', $resolvedBinding);
            return $engine->solve(
                (string) $request->input('sessionToken'),
                $resolvedBinding,
                (string) $request->input('challengeId'),
                (array) $request->input('answer'),
                $request->input('workNonce'),
            );
        });
    }

    private function run(callable $callback): JsonResponse
    {
        try {
            return response()->json($callback());
        } catch (CaptchaException $e) {
            return response()->json(['code' => $e->errorCode], $e->status);
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['code' => 'verification_unavailable'], 503);
        }
    }
}
