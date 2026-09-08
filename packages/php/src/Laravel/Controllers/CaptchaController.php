<?php
declare(strict_types=1);
namespace Jervis\Captcha\Laravel\Controllers;
use Illuminate\Http\JsonResponse;use Illuminate\Http\Request;use Illuminate\Routing\Controller;use Jervis\Captcha\CaptchaException;use Jervis\Captcha\Contracts\BindingResolver;use Jervis\Captcha\Engine\EmbeddedEngine;
final class CaptchaController extends Controller
{
    public function session(Request $request,string $action,EmbeddedEngine $engine,BindingResolver $binding): JsonResponse { if(!$engine->actionExists($action))return response()->json(['code'=>'invalid_context'],404);return $this->run(fn()=>$engine->createSession($action,$binding->resolve($request))); }
    public function challenge(Request $request,EmbeddedEngine $engine,BindingResolver $binding): JsonResponse { $request->validate(['sessionToken'=>['required','string','size:43']]);return $this->run(fn()=>$engine->issue((string)$request->input('sessionToken'),$binding->resolve($request))); }
    public function answer(Request $request,EmbeddedEngine $engine,BindingResolver $binding): JsonResponse { $request->validate(['sessionToken'=>['required','string','size:43'],'challengeId'=>['required','string','size:43'],'answer'=>['required','array'],'workNonce'=>['nullable','string','max:64']]);return $this->run(fn()=>$engine->solve((string)$request->input('sessionToken'),$binding->resolve($request),(string)$request->input('challengeId'),(array)$request->input('answer'),$request->input('workNonce'))); }
    private function run(callable $callback): JsonResponse { try{return response()->json($callback());}catch(CaptchaException $e){return response()->json(['code'=>$e->errorCode],$e->status);}catch(\Throwable $e){report($e);return response()->json(['code'=>'verification_unavailable'],503);} }
}
