<?php
declare(strict_types=1);

namespace Jervis\Captcha\Tests;

use Jervis\Captcha\Engine\EmbeddedEngine;
use Jervis\Captcha\Engine\MemoryStore;
use PHPUnit\Framework\TestCase;

final class StateFootprintTest extends TestCase
{
    public function testAudioAndImagePayloadsAreNotPersistedInSessionState(): void
    {
        foreach (['audio', 'text'] as $type) {
            if ($type === 'text' && !extension_loaded('gd')) continue;

            $store = new MemoryStore();
            $engine = new EmbeddedEngine($store, str_repeat('s', 40), [], [
                'types' => [$type],
                'accessibilityTypes' => [],
                'isAntiAI' => false,
                'workBits' => 0,
                'minSolveMs' => 0,
                'sessionTtlSeconds' => 60,
                'challengeTtlSeconds' => 30,
                'proofTtlSeconds' => 30,
                'maxAttempts' => 3,
                'maxRefreshes' => 3,
                'sessionsPerMinute' => 50,
            ]);

            $binding = str_repeat('b', 20);
            $session = $engine->createSession('signup', $binding);
            $publicChallenge = $engine->issue($session['sessionToken'], $binding);
            $state = $store->get(hash('sha256', $session['sessionToken']));

            self::assertIsArray($state);
            self::assertSame(['id', 'work'], array_keys($state['challenge']));
            self::assertSame($publicChallenge['id'], $state['challenge']['id']);
            self::assertArrayNotHasKey('audio', $state['challenge']);
            self::assertArrayNotHasKey('image', $state['challenge']);
            self::assertArrayNotHasKey('piece', $state['challenge']);
            self::assertArrayNotHasKey('prompt', $state['challenge']);
            self::assertLessThan(5000, strlen(serialize($state)));
        }
    }
}
