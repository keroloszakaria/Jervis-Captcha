<?php
declare(strict_types=1);

namespace Jervis\Captcha\Tests;

use InvalidArgumentException;
use Jervis\Captcha\Laravel\Support\EmbeddedSecret;
use PHPUnit\Framework\TestCase;

final class EmbeddedSecretTest extends TestCase
{
    public function testDerivationIsDeterministicDomainSeparatedAndSensitiveToBaseSecret(): void
    {
        $first = str_repeat('a', 40);
        $second = str_repeat('b', 40);

        $derived = EmbeddedSecret::derive($first);
        self::assertSame($derived, EmbeddedSecret::derive($first));
        self::assertNotSame($first, $derived);
        self::assertNotSame($derived, EmbeddedSecret::derive($second));
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $derived);
    }

    public function testShortBaseSecretIsRejectedBeforeDerivation(): void
    {
        $this->expectException(InvalidArgumentException::class);
        EmbeddedSecret::derive('too-short');
    }
}
