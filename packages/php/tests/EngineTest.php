<?php
declare(strict_types=1);
namespace Jervis\Captcha\Tests;
use Jervis\Captcha\CaptchaException;use Jervis\Captcha\Engine\EmbeddedEngine;use Jervis\Captcha\Engine\MemoryStore;use PHPUnit\Framework\TestCase;
final class EngineTest extends TestCase
{
    private function engine(string $type='pow'): EmbeddedEngine{return new EmbeddedEngine(new MemoryStore(),str_repeat('s',40),['signup'=>['types'=>[$type],'isAntiAI'=>false,'workBits'=>$type==='pow'?12:0,'minSolveMs'=>0,'sessionTtlSeconds'=>60,'challengeTtlSeconds'=>30,'proofTtlSeconds'=>30,'maxAttempts'=>3,'maxRefreshes'=>3,'sessionsPerMinute'=>50]]);}
    public function testPowProofIsOneUseAndBindingScoped(): void{$engine=$this->engine('pow');$binding=str_repeat('b',20);$session=$engine->createSession('signup',$binding);$challenge=$engine->issue($session['sessionToken'],$binding);$nonce=$this->solveWork($challenge['work']['seed'],$challenge['work']['bits']);$proof=$engine->solve($session['sessionToken'],$binding,$challenge['id'],[],$nonce);self::assertTrue($engine->consume($proof['token'],'signup',$binding));$this->expectException(CaptchaException::class);$engine->consume($proof['token'],'signup',$binding);}
    public function testWrongBindingCannotIssueChallenge(): void{$engine=$this->engine('pow');$session=$engine->createSession('signup',str_repeat('a',20));$this->expectException(CaptchaException::class);$engine->issue($session['sessionToken'],str_repeat('z',20));}
    private function solveWork(string $seed,int $bits): string{for($i=0;$i<10000000;$i++){$hash=hash('sha256',$seed.':'.$i,true);$valid=true;for($bit=0;$bit<$bits;$bit++)if((ord($hash[intdiv($bit,8)])&(1<<(7-($bit%8))))!==0){$valid=false;break;}if($valid)return(string)$i;}throw new \RuntimeException('work not solved');}
}
