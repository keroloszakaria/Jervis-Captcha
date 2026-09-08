@php
    $jervisPrefix = request()->getBaseUrl() . '/' . trim((string) config('jervis-captcha.routes.prefix', 'jervis-captcha'), '/') . '/';
@endphp
@once
<script type="module" src="{{ route('jervis-captcha.asset', ['file' => 'jervis-captcha.js', 'v' => \Jervis\Captcha\Engine\EmbeddedEngine::VERSION], false) }}"></script>
@endonce
<jervis-captcha action="{{ $action }}" endpoint="{{ $jervisPrefix }}" csrf-token="{{ csrf_token() }}" locale="{{ $locale }}" theme="{{ $theme }}" presentation="{{ $presentation }}" name="{{ $name }}"></jervis-captcha>
