<?php
declare(strict_types=1);

namespace Jervis\Captcha\Laravel\Support;

use RuntimeException;

final class EnvironmentSecretWriter
{
    /** @return array{secret:string,created:bool,rotated:bool} */
    public function write(string $path, bool $force = false): array
    {
        if (!is_file($path)) {
            throw new RuntimeException('Laravel .env file was not found. Set JERVIS_CAPTCHA_SECRET through the deployment environment instead.');
        }
        $contents = file_get_contents($path);
        if (!is_string($contents)) throw new RuntimeException('Unable to read Laravel .env file.');

        $pattern = '/^[ \t]*JERVIS_CAPTCHA_SECRET[ \t]*=([^\r\n]*)$/m';
        $matches = [];
        $matchCount = preg_match_all($pattern, $contents, $matches, PREG_SET_ORDER);
        if ($matchCount === false) {
            throw new RuntimeException('Unable to inspect JERVIS_CAPTCHA_SECRET in Laravel .env.');
        }
        if ($matchCount > 1) {
            throw new RuntimeException('Multiple JERVIS_CAPTCHA_SECRET entries were found in Laravel .env. Resolve the duplicate declarations before rotating the secret.');
        }
        $matched = $matchCount === 1;
        $existing = $matched ? trim((string) $matches[0][1], " \t\"'") : '';

        if ($existing !== '' && strlen($existing) < 32 && !$force) {
            throw new RuntimeException('Existing JERVIS_CAPTCHA_SECRET is too short. Replace it or explicitly rotate it with --force.');
        }
        if ($existing !== '' && !$force) {
            return ['secret' => $existing, 'created' => false, 'rotated' => false];
        }

        $secret = bin2hex(random_bytes(32));
        $line = 'JERVIS_CAPTCHA_SECRET=' . $secret;
        $newline = str_contains($contents, "\r\n") ? "\r\n" : "\n";

        if ($matched) {
            $updated = preg_replace($pattern, $line, $contents, 1);
            if (!is_string($updated)) throw new RuntimeException('Unable to update JERVIS_CAPTCHA_SECRET in .env.');
        } else {
            $updated = rtrim($contents, "\r\n") . $newline . $line . $newline;
        }

        if (file_put_contents($path, $updated, LOCK_EX) === false) {
            throw new RuntimeException('Unable to persist JERVIS_CAPTCHA_SECRET in .env.');
        }

        return [
            'secret' => $secret,
            'created' => !$matched || $existing === '',
            'rotated' => $matched && $existing !== '',
        ];
    }
}
