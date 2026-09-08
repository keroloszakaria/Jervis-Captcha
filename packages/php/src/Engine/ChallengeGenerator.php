<?php
declare(strict_types=1);
namespace Jervis\Captcha\Engine;
use Jervis\Captcha\CaptchaException;
final class ChallengeGenerator
{
    private const WIDTH = 300; private const HEIGHT = 160; private const PIECE = 44;
    public function generate(string $type, bool $antiAi): array
    {
        return match ($type) {
            'text' => $this->text($antiAi), 'slider' => $this->slider(false), 'drag' => $this->slider(true), 'rotate' => $this->rotate(), 'click' => $this->click(), 'math' => $this->math(), 'logic' => $this->logic(), 'pow' => [['id' => self::token(), 'type' => 'pow', 'prompt' => 'Background verification'], []], default => throw new CaptchaException('unsupported_type', 400),
        };
    }
    public static function token(): string { return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='); }
    private function canvas(int $width = self::WIDTH, int $height = self::HEIGHT): \GdImage
    {
        if (!extension_loaded('gd')) throw new CaptchaException('gd_required', 500);
        $im = imagecreatetruecolor($width, $height); $bg = imagecolorallocate($im, random_int(225, 245), random_int(225, 245), random_int(225, 245)); imagefill($im, 0, 0, $bg);
        for ($i = 0; $i < 28; $i++) { $c = imagecolorallocatealpha($im, random_int(80, 210), random_int(80, 210), random_int(80, 210), random_int(75, 115)); if ($i % 2 === 0) imageellipse($im, random_int(0, $width), random_int(0, $height), random_int(8, 45), random_int(8, 45), $c); else imageline($im, random_int(0, $width), random_int(0, $height), random_int(0, $width), random_int(0, $height), $c); }
        return $im;
    }
    private function png(\GdImage $im): string { ob_start(); imagepng($im, null, 7); $raw = (string) ob_get_clean(); imagedestroy($im); return 'data:image/png;base64,' . base64_encode($raw); }
    private function text(bool $antiAi): array
    {
        $alphabet = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ'; $answer = ''; for ($i = 0; $i < 6; $i++) $answer .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        $im = $this->canvas(260, 96); $dark = imagecolorallocate($im, 25, 35, 55);
        for ($i = 0; $i < strlen($answer); $i++) imagestring($im, 5, 22 + ($i * 36) + random_int(-3, 3), 34 + random_int(-8, 8), $answer[$i], $dark);
        for ($i = 0; $i < ($antiAi ? 95 : 45); $i++) { $c = imagecolorallocatealpha($im, random_int(0, 255), random_int(0, 255), random_int(0, 255), random_int(45, 105)); imagesetpixel($im, random_int(0, 259), random_int(0, 95), $c); }
        return [['id' => self::token(), 'type' => 'text', 'prompt' => 'Enter the characters shown', 'image' => $this->png($im)], ['text' => $answer]];
    }
    private function slider(bool $freeDrag): array
    {
        $im = $this->canvas(); $x = random_int(80, self::WIDTH - self::PIECE - 16); $y = random_int(20, self::HEIGHT - self::PIECE - 20);
        $piece = imagecreatetruecolor(self::PIECE, self::PIECE); imagecopy($piece, $im, 0, 0, $x, $y, self::PIECE, self::PIECE); $hole = imagecolorallocatealpha($im, 20, 25, 35, 45); imagefilledrectangle($im, $x, $y, $x + self::PIECE, $y + self::PIECE, $hole);
        $challenge = ['id' => self::token(), 'type' => $freeDrag ? 'drag' : 'slider', 'prompt' => $freeDrag ? 'Move the piece into the missing area' : 'Slide the piece into the missing area', 'image' => $this->png($im), 'piece' => $this->png($piece), 'width' => self::WIDTH, 'height' => self::HEIGHT, 'pieceSize' => self::PIECE, 'pieceTop' => $freeDrag ? 0 : $y];
        return [$challenge, $freeDrag ? ['x' => $x, 'y' => $y] : ['x' => $x]];
    }
    private function rotate(): array
    {
        $base = imagecreatetruecolor(120, 120); imagesavealpha($base, true); $transparent = imagecolorallocatealpha($base, 255, 255, 255, 127); imagefill($base, 0, 0, $transparent); $c = imagecolorallocate($base, 25, 45, 80);
        imagefilledpolygon($base, [[60,10],[95,70],[70,65],[70,108],[50,108],[50,65],[25,70]], $c); $rotation = random_int(20, 340); $rotated = imagerotate($base, -$rotation, $transparent); imagedestroy($base);
        return [['id' => self::token(), 'type' => 'rotate', 'prompt' => 'Rotate the arrow upright', 'image' => $this->png($rotated)], ['angle' => (360 - $rotation) % 360]];
    }
    private function click(): array
    {
        $im = $this->canvas(); $points = [];
        for ($i = 1; $i <= 3; $i++) { $x = random_int(35, 265); $y = random_int(30, 130); $points[] = ['x' => $x, 'y' => $y]; $c = imagecolorallocate($im, 20 + ($i * 45), 40 + ($i * 35), 120 + ($i * 25)); imagefilledellipse($im, $x, $y, 32, 32, $c); $white = imagecolorallocate($im, 255, 255, 255); imagestring($im, 5, $x - 4, $y - 8, (string) $i, $white); }
        return [['id' => self::token(), 'type' => 'click', 'prompt' => 'Select 1, 2 and 3 in order', 'image' => $this->png($im), 'width' => self::WIDTH, 'height' => self::HEIGHT], ['points' => $points]];
    }
    private function math(): array { $a = random_int(3, 19); $b = random_int(2, 15); return [['id' => self::token(), 'type' => 'math', 'prompt' => "{$a} + {$b} = ?"], ['text' => (string) ($a + $b)]]; }
    private function logic(): array { $start = random_int(2, 12); $step = random_int(2, 7); $values = [$start, $start + $step, $start + 2*$step, $start + 3*$step]; return [['id' => self::token(), 'type' => 'logic', 'prompt' => implode(', ', $values) . ', ?'], ['text' => (string) ($start + 4*$step)]]; }
}
