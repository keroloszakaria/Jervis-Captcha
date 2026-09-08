<?php
declare(strict_types=1);
namespace Jervis\Captcha;
use RuntimeException;
final class CaptchaException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, public readonly int $status = 400)
    {
        parent::__construct($errorCode, $status);
    }
}
