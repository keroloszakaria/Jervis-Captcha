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
    'routes'=>['enabled'=>true,'prefix'=>'jervis-captcha','middleware'=>['web']],
    'default_policy'=>['types'=>['text','slider','rotate'],'isAntiAI'=>true,'workBits'=>14,'minSolveMs'=>400,'sessionTtlSeconds'=>300,'challengeTtlSeconds'=>120,'proofTtlSeconds'=>60,'maxAttempts'=>3,'maxRefreshes'=>4,'sessionsPerMinute'=>120],
    // Optional per-action overrides. The browser can name an action but can never
    // choose its policy; unknown valid actions use the server-owned default above.
    'actions'=>[],
];
