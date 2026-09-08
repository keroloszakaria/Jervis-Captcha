<?php
declare(strict_types=1);

namespace Jervis\Captcha\Tests;

use InvalidArgumentException;
use Jervis\Captcha\Engine\EmbeddedEngine;
use Jervis\Captcha\Engine\MemoryStore;
use PHPUnit\Framework\TestCase;

final class PolicyOverrideTest extends TestCase
{
    public function testPartialActionOverrideInheritsProjectDefaultPolicy(): void
    {
        $store = new MemoryStore();
        $default = [
            'types' => ['text'],
            'accessibilityTypes' => ['audio'],
            'isAntiAI' => false,
            'workBits' => 0,
            'minSolveMs' => 777,
            'sessionTtlSeconds' => 240,
            'challengeTtlSeconds' => 90,
            'proofTtlSeconds' => 45,
            'maxAttempts' => 4,
            'maxRefreshes' => 7,
            'sessionsPerMinute' => 31,
        ];

        $engine = new EmbeddedEngine(
            $store,
            str_repeat('s', 40),
            ['login' => ['types' => ['pow'], 'workBits' => 12]],
            $default,
        );

        $session = $engine->createSession('login', str_repeat('b', 20));
        $state = $store->get(hash('sha256', $session['sessionToken']));
        self::assertIsArray($state);

        $policy = $state['policy'];
        self::assertSame(['pow'], $policy['types']);
        self::assertSame(['audio'], $policy['accessibilityTypes']);
        self::assertFalse($policy['isAntiAI']);
        self::assertSame(12, $policy['workBits']);
        self::assertSame(777, $policy['minSolveMs']);
        self::assertSame(240, $policy['sessionTtlSeconds']);
        self::assertSame(90, $policy['challengeTtlSeconds']);
        self::assertSame(45, $policy['proofTtlSeconds']);
        self::assertSame(4, $policy['maxAttempts']);
        self::assertSame(7, $policy['maxRefreshes']);
        self::assertSame(31, $policy['sessionsPerMinute']);
    }

    public function testActionOnlyModeRejectsImplicitLibraryDefaults(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must define every policy field');

        new EmbeddedEngine(
            new MemoryStore(),
            str_repeat('s', 40),
            ['login' => ['types' => ['pow'], 'workBits' => 12]],
            null,
        );
    }
}
