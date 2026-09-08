<?php
declare(strict_types=1);

namespace Jervis\Captcha\Laravel\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class LimitCaptchaPayload
{
    public const MAX_BYTES = 32768;

    public function handle(Request $request, Closure $next): Response
    {
        $length = $request->server('CONTENT_LENGTH');
        if ($length !== null && $length !== '') {
            if (!is_numeric($length) || (int) $length < 0 || (int) $length > self::MAX_BYTES) {
                return response()->json(['code' => 'payload_too_large'], 413);
            }
        }

        return $next($request);
    }
}
