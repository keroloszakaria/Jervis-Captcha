<?php
declare(strict_types=1);

namespace Jervis\Captcha\Laravel\Support;

use InvalidArgumentException;

final class EmbeddedSecret
{
    private const CONTEXT = "jervis-captcha.embedded.v1";

    public static function derive(string $baseSecret): string
    {
        if (strlen($baseSecret) < 32) {
            throw new InvalidArgumentException('Jervis Captcha base secret must be at least 32 bytes');
        }

        // Domain-separate CAPTCHA HMAC material from Laravel APP_KEY or an
        // optional dedicated JERVIS_CAPTCHA_SECRET without exposing either key.
        return hash_hmac('sha256', self::CONTEXT, $baseSecret);
    }
}
