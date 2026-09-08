<?php
declare(strict_types=1);
namespace Jervis\Captcha\Laravel\Controllers;
use Illuminate\Routing\Controller;use Symfony\Component\HttpFoundation\BinaryFileResponse;
final class AssetController extends Controller
{
    public function __invoke(): BinaryFileResponse { $path=dirname(__DIR__,3).'/resources/dist/jervis-captcha.js';abort_unless(is_file($path),503,'Jervis Captcha browser asset is not installed');return response()->file($path,['Content-Type'=>'text/javascript; charset=utf-8','Cache-Control'=>'public, max-age=31536000, immutable']); }
}
