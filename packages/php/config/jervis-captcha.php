<?php
use Jervis\Captcha\Laravel\Support\LaravelSessionBinding;
return [
    // A dedicated base secret is optional. APP_KEY is the zero-setup fallback;
    // the provider domain-separates either value before the engine uses it.
    // Rotating either base intentionally revokes outstanding sessions/proofs.
    'secret'=>env('JERVIS_CAPTCHA_SECRET',env('APP_KEY')),
    'store'=>env('JERVIS_CAPTCHA_STORE'),
    'cache_prefix'=>'jervis-captcha:',
    'field_name'=>env('JERVIS_CAPTCHA_FIELD_NAME','jervisCaptchaToken'),
    'binding_resolver'=>LaravelSessionBinding::class,
    'global_sessions_per_minute'=>(int)env('JERVIS_CAPTCHA_GLOBAL_SESSIONS_PER_MINUTE',300),
    // These budgets are deliberately action-independent so callers cannot evade
    // them by rotating action names. Per-session attempts/refreshes are enforced
    // separately by the verification engine policy.
    'request_limits'=>[
        'global_per_minute'=>(int)env('JERVIS_CAPTCHA_GLOBAL_REQUESTS_PER_MINUTE',5000),
        'session_per_binding_per_minute'=>(int)env('JERVIS_CAPTCHA_SESSIONS_PER_BINDING_PER_MINUTE',30),
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
    // Optional per-action overrides are merged over the server-owned default.
    // Set default_policy to null to switch to an explicit action allowlist only.
    'actions'=>[],
];
