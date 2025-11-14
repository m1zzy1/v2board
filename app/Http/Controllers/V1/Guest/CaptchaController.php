<?php

namespace App\Http\Controllers\V1\Guest;

use App\Http\Controllers\Controller;
use App\Services\LocalCaptchaService;
use Illuminate\Http\Request;

class CaptchaController extends Controller
{
    /**
     * GET /api/v1/guest/captcha
     * 返回 PNG 或 SVG 验证码图片，并将验证码写入 Session。
     */
    public function image(Request $request)
    {
        $code = null;
        try {
            $code = LocalCaptchaService::generateCode($request);
        } catch (\Throwable $e) {
            \Log::error('Captcha generation/session failed: ' . $e->getMessage());
        }
        $code = $code ?: 'AB12C';
        try {
            if ($code === 'AB12C' && $request->hasSession() && !$request->session()->has('captcha_code')) {
                $request->session()->put('captcha_code', strtolower($code));
                $request->session()->put('captcha_time', time());
            }
        } catch (\Throwable $e) {
            \Log::warning('Captcha fallback store failed: ' . $e->getMessage());
        }

        if (function_exists('imagecreatetruecolor')) {
            try {
                $png = LocalCaptchaService::renderImage($code);
                return response($png, 200, [
                    'Content-Type' => 'image/png',
                    'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
                    'Pragma' => 'no-cache',
                    'Expires' => '0',
                ]);
            } catch (\Throwable $e) {
                \Log::error('Captcha PNG render failed: ' . $e->getMessage());
            }
        }

        $svg = LocalCaptchaService::renderSvg($code);
        return response($svg, 200, [
            'Content-Type' => 'image/svg+xml',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ]);
    }
}
