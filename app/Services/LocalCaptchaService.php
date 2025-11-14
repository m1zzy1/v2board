<?php

namespace App\Services;

use Illuminate\Http\Request;

class LocalCaptchaService
{
    /**
     * 生成验证码并存入 Session（小写存储，忽略大小写）
     */
    public static function generateCode(Request $request, int $length = 5): string
    {
        $pool = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
        $code = '';
        for ($i = 0; $i < $length; $i++) {
            $code .= $pool[random_int(0, strlen($pool) - 1)];
        }
        $request->session()->put('captcha_code', strtolower($code));
        $request->session()->put('captcha_time', time());
        return $code;
    }

    /**
     * 校验验证码（大小写不敏感），成功后一次性消费
     */
    public static function verify(Request $request, ?string $input): bool
    {
        if (!$input) return false;
        $expected = $request->session()->get('captcha_code');
        if (!$expected) return false;
        $ok = strtolower(trim($input)) === $expected;
        if ($ok) {
            $request->session()->forget(['captcha_code', 'captcha_time']);
        }
        return $ok;
    }

    /**
     * 生成 PNG 图片（需要 GD）
     */
    public static function renderImage(string $code): string
    {
        $width = 130; $height = 42;
        $image = imagecreatetruecolor($width, $height);
        $bg = imagecolorallocate($image, 245, 247, 250);
        imagefilledrectangle($image, 0, 0, $width, $height, $bg);

        for ($i = 0; $i < 5; $i++) {
            $noise = imagecolorallocate($image, rand(150, 220), rand(150, 220), rand(150, 220));
            imageline($image, rand(0, $width), rand(0, $height), rand(0, $width), rand(0, $height), $noise);
        }

        $color = imagecolorallocate($image, 60, 60, 60);
        $x = 12;
        for ($i = 0; $i < strlen($code); $i++) {
            imagestring($image, 5, $x, rand(8, 16), $code[$i], $color);
            $x += 22;
        }

        ob_start();
        imagepng($image);
        imagedestroy($image);
        return (string)ob_get_clean();
    }

    /**
     * 当 GD 不可用时的 SVG 兜底
     */
    public static function renderSvg(string $code): string
    {
        $w = 130; $h = 42;
        $bg = '#f5f7fa';
        $fg = '#333333';
        $noise = '';
        for ($i = 0; $i < 6; $i++) {
            $x1 = rand(0, $w); $y1 = rand(0, $h); $x2 = rand(0, $w); $y2 = rand(0, $h);
            $c = sprintf('#%02x%02x%02x', rand(160,220), rand(160,220), rand(160,220));
            $noise .= "<line x1='$x1' y1='$y1' x2='$x2' y2='$y2' stroke='$c' stroke-width='1'/>";
        }
        $chars = '';
        $x = 12;
        for ($i = 0; $i < strlen($code); $i++) {
            $chars .= "<text x='$x' y='26' font-size='18' font-family='monospace' fill='$fg' transform='rotate(" . rand(-12,12) . " $x 26')>" . htmlspecialchars($code[$i]) . "</text>";
            $x += 22;
        }
        $svg = "<svg xmlns='http://www.w3.org/2000/svg' width='$w' height='$h' viewBox='0 0 $w $h'>"
             . "<rect width='100%' height='100%' fill='$bg'/>" . $noise . $chars . "</svg>";
        return $svg;
    }
}
