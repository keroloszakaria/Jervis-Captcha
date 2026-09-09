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
            if (!ctype_digit((string) $length) || (int) $length > self::MAX_BYTES) {
                return response()->json(['code' => 'payload_too_large'], 413);
            }
        } elseif (strlen($request->getContent()) > self::MAX_BYTES) {
            // Covers chunked/no-Content-Length requests at the application boundary.
            return response()->json(['code' => 'payload_too_large'], 413);
        }

        return $next($request);
    }
}
