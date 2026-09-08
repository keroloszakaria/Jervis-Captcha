<?php
declare(strict_types=1);

namespace Jervis\Captcha\Tests;

use InvalidArgumentException;
use Jervis\Captcha\Engine\Policy;
use PHPUnit\Framework\TestCase;

final class PolicyParsingTest extends TestCase
{
    public function testExplicitStringBooleanAndIntegerConfigurationIsParsedPredictably(): void
    {
        $policy = Policy::fromArray([
            'types' => ['pow'],
            'accessibilityTypes' => [],
            'isAntiAI' => 'false',
            'workBits' => '12',
            'minSolveMs' => '0',
            'sessionTtlSeconds' => '60',
            'challengeTtlSeconds' => '30',
            'proofTtlSeconds' => '30',
            'maxAttempts' => '3',
            'maxRefreshes' => '3',
            'sessionsPerMinute' => '50',
        ]);

        self::assertFalse($policy->isAntiAI);
        self::assertSame(12, $policy->workBits);
        self::assertSame(60, $policy->sessionTtlSeconds);
    }

    public function testUnknownAndAmbiguousValuesAreRejectedInsteadOfSilentlyCast(): void
    {
        foreach ([
            ['isAntiAI' => 'definitely'],
            ['workBits' => '12foo'],
            ['workBits' => 12.5],
            ['types' => 'pow'],
            ['accessibilityTypes' => 'audio'],
            ['typoPolicyField' => true],
        ] as $invalid) {
            try {
                Policy::fromArray($invalid);
                self::fail('Ambiguous policy config must be rejected: ' . json_encode($invalid));
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testCompletePolicyContractRejectsUnknownFields(): void
    {
        $complete = [
            'types' => ['text'],
            'accessibilityTypes' => ['audio'],
            'isAntiAI' => false,
            'workBits' => 0,
            'minSolveMs' => 400,
            'sessionTtlSeconds' => 300,
            'challengeTtlSeconds' => 120,
            'proofTtlSeconds' => 60,
            'maxAttempts' => 3,
            'maxRefreshes' => 4,
            'sessionsPerMinute' => 120,
        ];
        self::assertTrue(Policy::completeArray($complete));
        $complete['unknown'] = true;
        self::assertFalse(Policy::completeArray($complete));
    }
}
