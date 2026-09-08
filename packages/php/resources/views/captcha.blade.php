@once
<script type="module" src="{{ route('jervis-captcha.asset') }}"></script>
@endonce
<jervis-captcha action="{{ $action }}" endpoint="{{ rtrim(url(config('jervis-captcha.routes.prefix', 'jervis-captcha')), '/') }}/" locale="{{ $locale }}" theme="{{ $theme }}" presentation="{{ $presentation }}" name="{{ $name }}"></jervis-captcha>
