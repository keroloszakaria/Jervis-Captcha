<?php
declare(strict_types=1);

namespace Jervis\Captcha\Tests;

use Jervis\Captcha\Laravel\Support\EnvironmentSecretWriter;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class EnvironmentSecretWriterTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = sys_get_temp_dir() . '/jervis-captcha-env-' . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        parent::tearDown();
    }

    public function testMissingSecretIsGeneratedAndExistingEnvContentIsPreserved(): void
    {
        file_put_contents($this->path, "APP_NAME=Fixture\nFOO=bar\n");
        $writer = new EnvironmentSecretWriter();
        $result = $writer->write($this->path);

        self::assertTrue($result['created']);
        self::assertFalse($result['rotated']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result['secret']);
        $contents = (string) file_get_contents($this->path);
        self::assertStringContainsString("APP_NAME=Fixture\n", $contents);
        self::assertStringContainsString("FOO=bar\n", $contents);
        self::assertStringContainsString('JERVIS_CAPTCHA_SECRET=' . $result['secret'], $contents);

        $second = $writer->write($this->path);
        self::assertFalse($second['created']);
        self::assertFalse($second['rotated']);
        self::assertSame($result['secret'], $second['secret']);
    }

    public function testShortExistingSecretRequiresExplicitForceToRotate(): void
    {
        file_put_contents($this->path, "JERVIS_CAPTCHA_SECRET=short\nOTHER=value\n");
        $writer = new EnvironmentSecretWriter();

        try {
            $writer->write($this->path);
            self::fail('Short dedicated secret should be rejected without force.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('too short', $e->getMessage());
        }

        $rotated = $writer->write($this->path, true);
        self::assertFalse($rotated['created']);
        self::assertTrue($rotated['rotated']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $rotated['secret']);
        $contents = (string) file_get_contents($this->path);
        self::assertStringNotContainsString('JERVIS_CAPTCHA_SECRET=short', $contents);
        self::assertStringContainsString('JERVIS_CAPTCHA_SECRET=' . $rotated['secret'], $contents);
        self::assertStringContainsString('OTHER=value', $contents);
    }

    public function testEmptySecretLineIsFilledWithoutBeingClassifiedAsRotation(): void
    {
        file_put_contents($this->path, "A=1\r\nJERVIS_CAPTCHA_SECRET=\r\nB=2\r\n");
        $result = (new EnvironmentSecretWriter())->write($this->path);

        self::assertTrue($result['created']);
        self::assertFalse($result['rotated']);
        $contents = (string) file_get_contents($this->path);
        self::assertStringContainsString("\r\nJERVIS_CAPTCHA_SECRET={$result['secret']}\r\n", $contents);
    }

    public function testDuplicateSecretDeclarationsAreRejectedWithoutMutation(): void
    {
        $contents = "JERVIS_CAPTCHA_SECRET=" . str_repeat('a', 64) . "\nOTHER=value\nJERVIS_CAPTCHA_SECRET=" . str_repeat('b', 64) . "\n";
        file_put_contents($this->path, $contents);

        try {
            (new EnvironmentSecretWriter())->write($this->path, true);
            self::fail('Duplicate secret declarations must fail closed.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('Multiple JERVIS_CAPTCHA_SECRET entries', $e->getMessage());
        }

        self::assertSame($contents, file_get_contents($this->path));
    }

    public function testMissingEnvironmentFileIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        (new EnvironmentSecretWriter())->write($this->path);
    }
}
