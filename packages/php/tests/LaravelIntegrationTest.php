<?php
declare(strict_types=1);

namespace Jervis\Captcha\Tests;

use Illuminate\Support\Facades\Blade;
use Jervis\Captcha\Contracts\Store;
use Jervis\Captcha\Laravel\JervisCaptchaServiceProvider;
use Jervis\Captcha\Laravel\Support\RequestLimiter;
use Orchestra\Testbench\TestCase;

final class LaravelIntegrationTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [JervisCaptchaServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('k', 32)));
        $app['config']->set('cache.default', 'array');
        $app['config']->set('session.driver', 'array');
        $app['config']->set('jervis-captcha.secret', str_repeat('s', 40));
        $app['config']->set('jervis-captcha.store', 'array');
        $app['config']->set('jervis-captcha.request_limits', [
            'global_per_minute' => 1000,
            'challenge_per_binding_per_minute' => 100,
            'answer_per_binding_per_minute' => 100,
        ]);
        $app['config']->set('jervis-captcha.default_policy', [
            'types' => ['pow'], 'isAntiAI' => false, 'workBits' => 12, 'minSolveMs' => 0,
            'sessionTtlSeconds' => 60, 'challengeTtlSeconds' => 30, 'proofTtlSeconds' => 30,
            'maxAttempts' => 3, 'maxRefreshes' => 3, 'sessionsPerMinute' => 50,
        ]);
    }

    protected function defineRoutes($router): void
    {
        $router->middleware(['web', 'jervis.captcha:signup'])->post('/protected', static fn () => response()->json(['accepted' => true]));
    }

    public function testPackageOwnsSessionChallengeAnswerAndProtectedMiddlewareFlow(): void
    {
        $session = $this->postJson('/jervis-captcha/session/signup')->assertOk()->json();
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $session['sessionToken']);
        self::assertTrue($session['accessibilityAvailable']);
        self::assertArrayNotHasKey('policy', $session);
        self::assertArrayNotHasKey('types', $session);

        $challenge = $this->postJson('/jervis-captcha/v1/challenges', ['sessionToken' => $session['sessionToken']])->assertOk()->json();
        self::assertSame('pow', $challenge['type']);
        self::assertArrayNotHasKey('expected', $challenge);
        $nonce = $this->solveWork($challenge['work']['seed'], $challenge['work']['bits']);

        $proof = $this->postJson('/jervis-captcha/v1/answers', [
            'sessionToken' => $session['sessionToken'], 'challengeId' => $challenge['id'], 'answer' => [], 'workNonce' => $nonce,
        ])->assertOk()->json();

        $this->postJson('/protected', ['jervisCaptchaToken' => $proof['token']])->assertOk()->assertJson(['accepted' => true]);
        $this->postJson('/protected', ['jervisCaptchaToken' => $proof['token']])->assertStatus(422);
    }

    public function testAccessibleVariantUsesServerOwnedAudioPoolAndProducesAUsableProof(): void
    {
        $session = $this->postJson('/jervis-captcha/session/signup', ['variant' => 'accessible'])->assertOk()->json();
        self::assertTrue($session['accessibilityAvailable']);

        $challenge = $this->postJson('/jervis-captcha/v1/challenges', ['sessionToken' => $session['sessionToken']])->assertOk()->json();
        self::assertSame('audio', $challenge['type']);
        self::assertMatchesRegularExpression('/^data:audio\/wav;base64,[A-Za-z0-9+\/=]+$/', $challenge['audio']);
        self::assertArrayNotHasKey('expected', $challenge);

        $state = app(Store::class)->get(hash('sha256', $session['sessionToken']));
        self::assertIsArray($state);
        self::assertSame('accessible', $state['variant']);
        self::assertSame('audio', $state['type']);
        $answer = (string) ($state['expected']['text'] ?? '');
        self::assertMatchesRegularExpression('/^[0-9]{5}$/', $answer);

        $proof = $this->postJson('/jervis-captcha/v1/answers', [
            'sessionToken' => $session['sessionToken'],
            'challengeId' => $challenge['id'],
            'answer' => ['text' => $answer],
        ])->assertOk()->json();
        $this->postJson('/protected', ['jervisCaptchaToken' => $proof['token']])->assertOk();
    }

    public function testBrowserCannotForceChallengeTypeOrTrustInputs(): void
    {
        foreach ([
            ['type' => 'pow'],
            ['binding' => str_repeat('x', 20)],
            ['isAntiAI' => false],
            ['policy' => ['types' => ['pow']]],
            ['variant' => 'audio'],
        ] as $payload) {
            $this->postJson('/jervis-captcha/session/signup', $payload)->assertStatus(422);
        }
    }

    public function testActionCanDisableAccessibleVariantEntirely(): void
    {
        $policy = (array) config('jervis-captcha.default_policy');
        $policy['accessibilityTypes'] = [];
        $this->app['config']->set('jervis-captcha.default_policy', $policy);

        $standard = $this->postJson('/jervis-captcha/session/signup')->assertOk()->json();
        self::assertFalse($standard['accessibilityAvailable']);
        $this->postJson('/jervis-captcha/session/signup', ['variant' => 'accessible'])
            ->assertStatus(400)
            ->assertJson(['code' => 'accessibility_unavailable']);
    }

    public function testProofForAnotherActionCannotPassMiddleware(): void
    {
        $session = $this->postJson('/jervis-captcha/session/login')->assertOk()->json();
        $challenge = $this->postJson('/jervis-captcha/v1/challenges', ['sessionToken' => $session['sessionToken']])->assertOk()->json();
        $proof = $this->postJson('/jervis-captcha/v1/answers', [
            'sessionToken' => $session['sessionToken'], 'challengeId' => $challenge['id'], 'answer' => [],
            'workNonce' => $this->solveWork($challenge['work']['seed'], $challenge['work']['bits']),
        ])->assertOk()->json();
        $this->postJson('/protected', ['jervisCaptchaToken' => $proof['token']])->assertStatus(422);
    }

    public function testChallengeBudgetCannotBeBypassedByRotatingActions(): void
    {
        $this->app['config']->set('jervis-captcha.request_limits.challenge_per_binding_per_minute', 2);
        $this->app['config']->set('jervis-captcha.request_limits.global_per_minute', 100);

        $first = $this->postJson('/jervis-captcha/session/action-a')->assertOk()->json('sessionToken');
        $second = $this->postJson('/jervis-captcha/session/action-b')->assertOk()->json('sessionToken');
        $third = $this->postJson('/jervis-captcha/session/action-c')->assertOk()->json('sessionToken');

        $this->postJson('/jervis-captcha/v1/challenges', ['sessionToken' => $first])->assertOk();
        $this->postJson('/jervis-captcha/v1/challenges', ['sessionToken' => $second])->assertOk();
        $this->postJson('/jervis-captcha/v1/challenges', ['sessionToken' => $third])
            ->assertStatus(429)
            ->assertJson(['code' => 'rate_limited']);
    }

    public function testInvalidRequestBudgetFailsClosed(): void
    {
        $this->app['config']->set('jervis-captcha.request_limits.global_per_minute', 0);
        self::assertFalse(RequestLimiter::configurationValid());

        $session = $this->postJson('/jervis-captcha/session/signup')->assertOk()->json('sessionToken');
        $this->postJson('/jervis-captcha/v1/challenges', ['sessionToken' => $session])
            ->assertStatus(503)
            ->assertJson(['code' => 'verification_unavailable']);
    }

    public function testBladeComponentNeedsOnlyAnAction(): void
    {
        $html = Blade::render('<x-jervis-captcha action="signup" locale="ar" />');
        self::assertStringContainsString('action="signup"', $html);
        self::assertStringContainsString('locale="ar"', $html);
        self::assertStringContainsString('csrf-token=', $html);
        self::assertStringContainsString('endpoint="/jervis-captcha/"', $html);
        self::assertStringContainsString('/jervis-captcha/assets/jervis-captcha.js', $html);
        self::assertStringNotContainsString('session-token=', $html);
        self::assertStringNotContainsString('http://', $html);
        self::assertStringNotContainsString('https://', $html);
    }

    public function testPackageServesOnlyItsBrowserRuntimeModules(): void
    {
        $entry = $this->get('/jervis-captcha/assets/jervis-captcha.js')->assertOk()->getContent();
        self::assertIsString($entry);
        self::assertGreaterThan(1000, strlen($entry));
        self::assertStringContainsString('jervis-captcha', $entry);

        // The checked-in fallback uses a validated base bundle. A canonical
        // Vite build may inline everything into the entry. Test whichever
        // layout is actually packaged instead of coupling release acceptance
        // to that build detail.
        if (str_contains($entry, './jervis-captcha-base.js')) {
            $this->get('/jervis-captcha/assets/jervis-captcha-base.js')->assertOk();
        }

        $this->get('/jervis-captcha/assets/THIRD_PARTY_NOTICES.txt')->assertNotFound();
        $this->get('/jervis-captcha/assets/../config/jervis-captcha.php')->assertNotFound();
    }

    private function solveWork(string $seed, int $bits): string
    {
        for ($i = 0; $i < 10_000_000; $i++) {
            $hash = hash('sha256', $seed . ':' . $i, true); $valid = true;
            for ($bit = 0; $bit < $bits; $bit++) {
                if ((ord($hash[intdiv($bit, 8)]) & (1 << (7 - ($bit % 8)))) !== 0) { $valid = false; break; }
            }
            if ($valid) return (string) $i;
        }
        throw new \RuntimeException('work not solved');
    }
}
