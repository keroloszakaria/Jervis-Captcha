<?php
use Jervis\Captcha\Laravel\Support\LaravelSessionBinding;
return [
    // Dedicated secret is recommended. APP_KEY is a safe zero-setup fallback and
    // rotating it intentionally revokes outstanding CAPTCHA sessions/proofs.
    'secret'=>env('JERVIS_CAPTCHA_SECRET',env('APP_KEY')),
    'store'=>env('JERVIS_CAPTCHA_STORE'),
    'cache_prefix'=>'jervis-captcha:',
    'binding_resolver'=>LaravelSessionBinding::class,
    'global_sessions_per_minute'=>(int)env('JERVIS_CAPTCHA_GLOBAL_SESSIONS_PER_MINUTE',300),
    // These budgets are deliberately action-independent so callers cannot evade
    // them by rotating action names. Per-session attempts/refreshes are enforced
    // separately by the verification engine policy.
    'request_limits'=>[
        'global_per_minute'=>(int)env('JERVIS_CAPTCHA_GLOBAL_REQUESTS_PER_MINUTE',5000),
        'challenge_per_binding_per_minute'=>(int)env('JERVIS_CAPTCHA_CHALLENGES_PER_BINDING_PER_MINUTE',30),
        'answer_per_binding_per_minute'=>(int)env('JERVIS_CAPTCHA_ANSWERS_PER_BINDING_PER_MINUTE',60),
    ],
    'routes'=>['enabled'=>true,'prefix'=>'jervis-captcha','middleware'=>['web']],
    'default_policy'=>[
        'types'=>['text','slider','rotate'],
        // The browser can request the accessible variant, but it cannot select
        // a concrete challenge type. Only this server-owned allowlist is used.
        'accessibilityTypes'=>['audio'],
        'isAntiAI'=>true,'workBits'=>14,'minSolveMs'=>400,'sessionTtlSeconds'=>300,
        'challengeTtlSeconds'=>120,'proofTtlSeconds'=>60,'maxAttempts'=>3,
        'maxRefreshes'=>4,'sessionsPerMinute'=>120,
    ],
    // Optional per-action overrides. The browser can name an action but can never
    // choose its policy; unknown valid actions use the server-owned default above.
    'actions'=>[],
];
