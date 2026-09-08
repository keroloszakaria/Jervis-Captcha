<?php
use Jervis\Captcha\Laravel\Support\LaravelSessionBinding;
return [
    'secret'=>env('JERVIS_CAPTCHA_SECRET',env('APP_KEY')),
    'store'=>env('JERVIS_CAPTCHA_STORE'),
    'cache_prefix'=>'jervis-captcha:',
    'binding_resolver'=>LaravelSessionBinding::class,
    'routes'=>['enabled'=>true,'prefix'=>'jervis-captcha','middleware'=>['web']],
    'actions'=>[
        'login'=>['types'=>['text','slider','rotate'],'isAntiAI'=>true,'workBits'=>14,'minSolveMs'=>400,'sessionTtlSeconds'=>300,'challengeTtlSeconds'=>120,'proofTtlSeconds'=>60,'maxAttempts'=>3,'maxRefreshes'=>4,'sessionsPerMinute'=>120],
    ],
];
