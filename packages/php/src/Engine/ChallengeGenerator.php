<?php
declare(strict_types=1);

namespace Jervis\Captcha\Engine;

use GdImage;
use Jervis\Captcha\CaptchaException;

final class ChallengeGenerator
{
    private const WIDTH = 300;
    private const HEIGHT = 160;
    private const PIECE = 44;
    private const CLICK_MIN_DISTANCE = 58.0;

    public function generate(string $type, bool $antiAi): array
    {
        return match ($type) {
            'text' => $this->text($antiAi),
            'slider' => $this->slider(false),
            'drag' => $this->slider(true),
            'rotate' => $this->rotate(),
            'click' => $this->click(),
            'math' => $this->math(),
            'logic' => $this->logic(),
            'pow' => [[
                'id' => self::token(),
                'type' => 'pow',
                'prompt' => 'Background verification',
            ], []],
            default => throw new CaptchaException('unsupported_type', 400),
        };
    }

    public static function token(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private function canvas(int $width = self::WIDTH, int $height = self::HEIGHT): GdImage
    {
        if (!extension_loaded('gd')) throw new CaptchaException('gd_required', 500);
        $image = imagecreatetruecolor($width, $height);
        if (!$image instanceof GdImage) throw new CaptchaException('render_unavailable', 503);

        $background = imagecolorallocate($image, random_int(225, 245), random_int(225, 245), random_int(225, 245));
        imagefill($image, 0, 0, $background);
        for ($i = 0; $i < 28; $i++) {
            $color = imagecolorallocatealpha($image, random_int(80, 210), random_int(80, 210), random_int(80, 210), random_int(75, 115));
            if ($i % 2 === 0) {
                imageellipse($image, random_int(0, $width - 1), random_int(0, $height - 1), random_int(8, 45), random_int(8, 45), $color);
            } else {
                imageline($image, random_int(0, $width - 1), random_int(0, $height - 1), random_int(0, $width - 1), random_int(0, $height - 1), $color);
            }
        }
        return $image;
    }

    private function png(GdImage $image): string
    {
        ob_start();
        try {
            if (!imagepng($image, null, 7)) throw new CaptchaException('render_unavailable', 503);
            $raw = ob_get_contents();
            if (!is_string($raw) || $raw === '') throw new CaptchaException('render_unavailable', 503);
        } finally {
            ob_end_clean();
            imagedestroy($image);
        }
        return 'data:image/png;base64,' . base64_encode($raw);
    }

    private function text(bool $antiAi): array
    {
        $alphabet = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
        $answer = '';
        for ($i = 0; $i < 6; $i++) $answer .= $alphabet[random_int(0, strlen($alphabet) - 1)];

        $image = $this->canvas(260, 96);
        $dark = imagecolorallocate($image, 25, 35, 55);
        for ($i = 0; $i < strlen($answer); $i++) {
            imagestring($image, 5, 22 + ($i * 36) + random_int(-3, 3), 34 + random_int(-8, 8), $answer[$i], $dark);
        }
        for ($i = 0; $i < ($antiAi ? 95 : 45); $i++) {
            $color = imagecolorallocatealpha($image, random_int(0, 255), random_int(0, 255), random_int(0, 255), random_int(45, 105));
            imagesetpixel($image, random_int(0, 259), random_int(0, 95), $color);
        }

        return [[
            'id' => self::token(),
            'type' => 'text',
            'prompt' => 'Enter the characters shown',
            'image' => $this->png($image),
        ], ['text' => $answer]];
    }

    private function slider(bool $freeDrag): array
    {
        $image = $this->canvas();
        $x = random_int(80, self::WIDTH - self::PIECE - 16);
        $y = random_int(20, self::HEIGHT - self::PIECE - 20);
        $piece = imagecreatetruecolor(self::PIECE, self::PIECE);
        if (!$piece instanceof GdImage) {
            imagedestroy($image);
            throw new CaptchaException('render_unavailable', 503);
        }
        if (!imagecopy($piece, $image, 0, 0, $x, $y, self::PIECE, self::PIECE)) {
            imagedestroy($piece);
            imagedestroy($image);
            throw new CaptchaException('render_unavailable', 503);
        }

        $hole = imagecolorallocatealpha($image, 20, 25, 35, 45);
        imagefilledrectangle($image, $x, $y, $x + self::PIECE - 1, $y + self::PIECE - 1, $hole);
        $challenge = [
            'id' => self::token(),
            'type' => $freeDrag ? 'drag' : 'slider',
            'prompt' => $freeDrag ? 'Move the piece into the missing area' : 'Slide the piece into the missing area',
            'image' => $this->png($image),
            'piece' => $this->png($piece),
            'width' => self::WIDTH,
            'height' => self::HEIGHT,
            'pieceSize' => self::PIECE,
            'pieceTop' => $freeDrag ? 0 : $y,
        ];

        return [$challenge, $freeDrag ? ['x' => $x, 'y' => $y] : ['x' => $x]];
    }

    private function rotate(): array
    {
        $base = imagecreatetruecolor(120, 120);
        if (!$base instanceof GdImage) throw new CaptchaException('render_unavailable', 503);
        imagesavealpha($base, true);
        $transparent = imagecolorallocatealpha($base, 255, 255, 255, 127);
        imagefill($base, 0, 0, $transparent);
        $color = imagecolorallocate($base, 25, 45, 80);
        imagefilledpolygon($base, [[60, 10], [95, 70], [70, 65], [70, 108], [50, 108], [50, 65], [25, 70]], $color);
        $rotation = random_int(20, 340);
        $rotated = imagerotate($base, -$rotation, $transparent);
        imagedestroy($base);
        if (!$rotated instanceof GdImage) throw new CaptchaException('render_unavailable', 503);

        return [[
            'id' => self::token(),
            'type' => 'rotate',
            'prompt' => 'Rotate the arrow upright',
            'image' => $this->png($rotated),
        ], ['angle' => (360 - $rotation) % 360]];
    }

    private function click(): array
    {
        $image = $this->canvas();
        $points = $this->spacedClickPoints();
        foreach ($points as $index => $point) {
            $number = $index + 1;
            $color = imagecolorallocate($image, 20 + ($number * 45), 40 + ($number * 35), 120 + ($number * 25));
            imagefilledellipse($image, $point['x'], $point['y'], 32, 32, $color);
            $white = imagecolorallocate($image, 255, 255, 255);
            imagestring($image, 5, $point['x'] - 4, $point['y'] - 8, (string) $number, $white);
        }

        return [[
            'id' => self::token(),
            'type' => 'click',
            'prompt' => 'Select 1, 2 and 3 in order',
            'image' => $this->png($image),
            'width' => self::WIDTH,
            'height' => self::HEIGHT,
        ], ['points' => $points]];
    }

    private function spacedClickPoints(): array
    {
        $points = [];
        for ($index = 0; $index < 3; $index++) {
            $candidate = null;
            for ($attempt = 0; $attempt < 64; $attempt++) {
                $next = ['x' => random_int(35, 265), 'y' => random_int(30, 130)];
                $farEnough = true;
                foreach ($points as $point) {
                    if (hypot($next['x'] - $point['x'], $next['y'] - $point['y']) < self::CLICK_MIN_DISTANCE) {
                        $farEnough = false;
                        break;
                    }
                }
                if ($farEnough) { $candidate = $next; break; }
            }
            if ($candidate === null) {
                return [
                    ['x' => 55, 'y' => 45],
                    ['x' => 150, 'y' => 115],
                    ['x' => 245, 'y' => 45],
                ];
            }
            $points[] = $candidate;
        }
        return $points;
    }

    private function math(): array
    {
        $a = random_int(3, 19);
        $b = random_int(2, 15);
        return [['id' => self::token(), 'type' => 'math', 'prompt' => "{$a} + {$b} = ?"], ['text' => (string) ($a + $b)]];
    }

    private function logic(): array
    {
        $start = random_int(2, 12);
        $step = random_int(2, 7);
        $values = [$start, $start + $step, $start + (2 * $step), $start + (3 * $step)];
        return [['id' => self::token(), 'type' => 'logic', 'prompt' => implode(', ', $values) . ', ?'], ['text' => (string) ($start + (4 * $step))]];
    }
}
