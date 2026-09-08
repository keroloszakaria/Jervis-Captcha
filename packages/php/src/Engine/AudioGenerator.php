<?php
declare(strict_types=1);

namespace Jervis\Captcha\Engine;

use Jervis\Captcha\CaptchaException;

final class AudioGenerator
{
    /** @var array<string,string>|null */
    private static ?array $samples = null;

    public function render(string $digits): string
    {
        if (!preg_match('/^[0-9]{1,8}$/D', $digits)) {
            throw new CaptchaException('audio_generation_failed', 500);
        }

        $samples = $this->samples();
        $silence = str_repeat(chr(128), 1000);
        $pcm = $silence;

        foreach (str_split($digits) as $digit) {
            $encoded = $samples[$digit] ?? null;
            if (!is_string($encoded)) throw new CaptchaException('audio_generation_failed', 500);
            $sample = base64_decode($encoded, true);
            if (!is_string($sample) || $sample === '' || strlen($sample) > 12000) {
                throw new CaptchaException('audio_generation_failed', 500);
            }
            $pcm .= $sample . $silence;
        }

        if (strlen($pcm) > 120000) throw new CaptchaException('audio_generation_failed', 500);
        $wav = 'RIFF'
            . pack('V', 36 + strlen($pcm))
            . 'WAVEfmt '
            . pack('VvvVVvv', 16, 1, 1, 8000, 8000, 1, 8)
            . 'data'
            . pack('V', strlen($pcm))
            . $pcm;

        return 'data:audio/wav;base64,' . base64_encode($wav);
    }

    /** @return array<string,string> */
    private function samples(): array
    {
        if (self::$samples !== null) return self::$samples;
        if (!function_exists('gzdecode')) throw new CaptchaException('zlib_required', 500);

        $path = dirname(__DIR__, 2) . '/resources/audio/digits.asset';
        $source = @file_get_contents($path);
        if (!is_string($source) || $source === '' || strlen($source) > 100000) {
            throw new CaptchaException('audio_asset_missing', 500);
        }
        if (!preg_match('/const digitAudioGzip = "([A-Za-z0-9+\/=]+)"/', $source, $match)) {
            throw new CaptchaException('audio_asset_invalid', 500);
        }

        $compressed = base64_decode($match[1], true);
        if (!is_string($compressed)) throw new CaptchaException('audio_asset_invalid', 500);
        $json = gzdecode($compressed, 300000);
        if (!is_string($json)) throw new CaptchaException('audio_asset_invalid', 500);
        try {
            $decoded = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new CaptchaException('audio_asset_invalid', 500);
        }
        if (!is_array($decoded) || count($decoded) !== 10) throw new CaptchaException('audio_asset_invalid', 500);

        $samples = [];
        foreach (range(0, 9) as $digit) {
            $key = (string) $digit;
            $value = $decoded[$key] ?? null;
            if (!is_string($value) || strlen($value) > 20000 || base64_decode($value, true) === false) {
                throw new CaptchaException('audio_asset_invalid', 500);
            }
            $samples[$key] = $value;
        }

        return self::$samples = $samples;
    }
}
