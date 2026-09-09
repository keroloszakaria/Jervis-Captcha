<?php
declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Jervis\Captcha\Contracts\BindingResolver;
use Tests\TestCase;

final class JervisCaptchaConsumerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Laravel's Feature test client does not preserve one browser session ID
        // across independent postJson() calls. Keep this fixture focused on the
        // package-owned consumer flow; real HTTP cookie/session binding is tested
        // separately by RealHttpAcceptance.php.
        $this->app->instance(BindingResolver::class, new class implements BindingResolver {
            public function resolve(Request $request): string { return 'fresh-consumer-fixed-binding-0001'; }
        });

        config()->set('cache.default', 'file');
        config()->set('session.driver', 'file');
        config()->set('jervis-captcha.store', 'file');
        config()->set('jervis-captcha.secret', str_repeat('s', 40));
        config()->set('jervis-captcha.global_sessions_per_minute', 1000);
        config()->set('jervis-captcha.request_limits', [
            'global_per_minute' => 1000,
            'session_per_binding_per_minute' => 100,
            'challenge_per_binding_per_minute' => 100,
            'answer_per_binding_per_minute' => 100,
        ]);
        config()->set('jervis-captcha.default_policy', [
            'types' => ['pow'],
            'accessibilityTypes' => ['audio'],
            'isAntiAI' => false,
            'workBits' => 12,
            'minSolveMs' => 0,
            'sessionTtlSeconds' => 60,
            'challengeTtlSeconds' => 30,
            'proofTtlSeconds' => 30,
            'maxAttempts' => 3,
            'maxRefreshes' => 3,
            'sessionsPerMinute' => 100,
        ]);

        Route::middleware(['web', 'jervis.captcha:signup'])
            ->post('/consumer-protected', static fn () => response()->json(['accepted' => true]));
    }

    public function testFreshLaravelAppUsesPackageOwnedFlowWithoutApplicationCaptchaLogic(): void
    {
        $session = $this->postJson('/jervis-captcha/session/signup', ['variant' => 'standard'])
            ->assertOk()
            ->assertJson([
                'accessibilityAvailable' => true,
                'fieldName' => 'jervisCaptchaToken',
            ])
            ->json();

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $session['sessionToken']);

        $challenge = $this->postJson('/jervis-captcha/v1/challenges', [
            'sessionToken' => $session['sessionToken'],
        ])->assertOk()->assertJson(['type' => 'pow'])->json();

        $proof = $this->postJson('/jervis-captcha/v1/answers', [
            'sessionToken' => $session['sessionToken'],
            'challengeId' => $challenge['id'],
            'answer' => [],
            'workNonce' => $this->solveWork($challenge['work']['seed'], $challenge['work']['bits']),
        ])->assertOk()->json('token');

        self::assertIsString($proof);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}\.[A-Za-z0-9_-]{43}$/', $proof);

        $this->postJson('/consumer-protected', ['jervisCaptchaToken' => $proof])
            ->assertOk()
            ->assertJson(['accepted' => true]);

        $this->postJson('/consumer-protected', ['jervisCaptchaToken' => $proof])
            ->assertUnprocessable();
    }

    public function testFreshLaravelAppGetsServerOwnedAccessibleAudioVariant(): void
    {
        $session = $this->postJson('/jervis-captcha/session/signup', ['variant' => 'accessible'])
            ->assertOk()
            ->assertJson(['accessibilityAvailable' => true])
            ->json('sessionToken');

        $challenge = $this->postJson('/jervis-captcha/v1/challenges', [
            'sessionToken' => $session,
        ])->assertOk()->assertJson(['type' => 'audio'])->json();

        self::assertArrayHasKey('audio', $challenge);
        self::assertMatchesRegularExpression('/^data:audio\/wav;base64,[A-Za-z0-9+\/=]+$/', $challenge['audio']);
        self::assertArrayNotHasKey('expected', $challenge);
        self::assertStringNotContainsString('http://', $challenge['audio']);
        self::assertStringNotContainsString('https://', $challenge['audio']);
    }

    public function testFreshLaravelAppRejectsBrowserPolicyAndTypeForcing(): void
    {
        $this->postJson('/jervis-captcha/session/signup', [
            'variant' => 'standard',
            'type' => 'audio',
        ])->assertUnprocessable();

        $this->postJson('/jervis-captcha/session/signup', [
            'variant' => 'standard',
            'binding' => str_repeat('x', 32),
        ])->assertUnprocessable();

        $this->postJson('/jervis-captcha/session/signup', [
            'variant' => 'standard',
            'policy' => ['types' => ['pow']],
        ])->assertUnprocessable();
    }

    private function solveWork(string $seed, int $bits): string
    {
        for ($nonce = 0; $nonce < 10_000_000; $nonce++) {
            $hash = hash('sha256', $seed . ':' . $nonce, true);
            $valid = true;
            for ($bit = 0; $bit < $bits; $bit++) {
                if ((ord($hash[intdiv($bit, 8)]) & (1 << (7 - ($bit % 8)))) !== 0) {
                    $valid = false;
                    break;
                }
            }
            if ($valid) return (string) $nonce;
        }

        throw new \RuntimeException('Unable to solve bounded consumer PoW fixture');
    }
}
