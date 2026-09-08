<?php
declare(strict_types=1);

namespace Jervis\Captcha\Laravel\Support;

use InvalidArgumentException;

final class EmbeddedConfig
{
    public static function fieldName(): string
    {
        $value = (string) config('jervis-captcha.field_name', 'jervisCaptchaToken');
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_-]{0,63}$/D', $value) !== 1) {
            throw new InvalidArgumentException('Invalid Jervis Captcha field_name configuration');
        }
        return $value;
    }

    public static function routePrefix(): string
    {
        $value = trim((string) config('jervis-captcha.routes.prefix', 'jervis-captcha'), '/');
        if ($value === '' || strlen($value) > 128
            || preg_match('/^[A-Za-z0-9._-]+(?:\/[A-Za-z0-9._-]+)*$/D', $value) !== 1) {
            throw new InvalidArgumentException('Invalid Jervis Captcha route prefix configuration');
        }
        return $value;
    }

    /** @return list<string> */
    public static function routeMiddleware(): array
    {
        $value = config('jervis-captcha.routes.middleware', ['web']);
        if (!is_array($value) || $value === []) {
            throw new InvalidArgumentException('Jervis Captcha route middleware must be a non-empty array');
        }

        $result = [];
        foreach ($value as $middleware) {
            if (!is_string($middleware) || $middleware === '' || strlen($middleware) > 128) {
                throw new InvalidArgumentException('Invalid Jervis Captcha route middleware entry');
            }
            $result[] = $middleware;
        }
        $result = array_values(array_unique($result));
        if (!in_array('web', $result, true)) {
            throw new InvalidArgumentException('Embedded managed CAPTCHA routes require Laravel web middleware for session and CSRF protection');
        }
        return $result;
    }

    public static function validFieldName(): bool
    {
        try { self::fieldName(); return true; } catch (InvalidArgumentException) { return false; }
    }

    public static function validRouteConfiguration(): bool
    {
        try {
            self::routePrefix();
            self::routeMiddleware();
            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }
}
