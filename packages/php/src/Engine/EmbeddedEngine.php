<?php
declare(strict_types=1);
namespace Jervis\Captcha\Engine;
use Jervis\Captcha\CaptchaException;
use Jervis\Captcha\Contracts\Store;
final class EmbeddedEngine
{
    public const VERSION = '0.2.0-alpha.1';
    private array $actions = [];
    public function __construct(private readonly Store $store, private readonly string $secret, array $actions, private readonly ?ChallengeGenerator $generator = null)
    {
        if (strlen($secret) < 32) throw new \InvalidArgumentException('Jervis Captcha secret must be at least 32 bytes');
        if ($actions === []) throw new \InvalidArgumentException('At least one CAPTCHA action is required');
        foreach ($actions as $action => $policy) { if (!is_string($action) || $action === '' || strlen($action) > 64) throw new \InvalidArgumentException('Invalid CAPTCHA action'); $this->actions[$action] = $policy instanceof Policy ? $policy : Policy::fromArray((array) $policy); }
    }
    public function createSession(string $action, string $binding, ?string $type = null): array
    {
        $policy = $this->policy($action); $this->validateBinding($binding); $kind = $type ?? $policy->types[array_rand($policy->types)]; if (!in_array($kind, $policy->types, true)) throw new CaptchaException('type_not_allowed', 400);
        if (!$this->store->rateLimit("site:{$action}", $policy->sessionsPerMinute, 60)) throw new CaptchaException('rate_limited', 429); $bound = $this->bind($binding); if (!$this->store->rateLimit("binding:{$action}:{$bound}", min(12, $policy->sessionsPerMinute), 60)) throw new CaptchaException('rate_limited', 429);
        $token = ChallengeGenerator::token(); $now = self::now(); $ttl = $policy->sessionTtlSeconds * 1000; $state = ['id' => hash('sha256', $token), 'action' => $action, 'binding' => $bound, 'type' => $kind, 'policy' => $policy->toArray(), 'createdMs' => $now, 'expiresMs' => $now + $ttl, 'state' => 'new', 'refreshes' => 0, 'attempts' => 0, 'challenge' => null, 'expected' => null, 'challengeCreatedMs' => 0, 'challengeExpiresMs' => 0, 'proofHash' => '', 'proofExpiresMs' => 0];
        $this->store->create($state['id'], $state, $policy->sessionTtlSeconds); return ['sessionToken' => $token, 'expiresInMs' => $ttl];
    }
    public function issue(string $sessionToken, string $binding): array
    {
        $id = $this->sessionId($sessionToken); $now = self::now();
        return $this->store->transaction($id, 900, function (?array $s) use ($now, $binding): array { $s = $this->active($s, $binding, $now); $policy = Policy::fromArray($s['policy']); if (in_array($s['state'], ['solved','consumed'], true)) throw new CaptchaException('session_finished', 409); if ($s['attempts'] >= $policy->maxAttempts || $s['refreshes'] >= $policy->maxRefreshes) throw new CaptchaException('session_locked', 429); [$challenge,$expected] = ($this->generator ?? new ChallengeGenerator())->generate($s['type'], $policy->isAntiAI); if ($policy->isAntiAI || $s['type'] === 'pow') $challenge['work'] = ['seed' => ChallengeGenerator::token(), 'bits' => $policy->workBits]; $expires = min($s['expiresMs'], $now + ($policy->challengeTtlSeconds * 1000)); $challenge['expiresInMs'] = $expires - $now; $challenge['minimumDelayMs'] = $policy->minSolveMs; $s['state']='challenge'; $s['refreshes']++; $s['challengeCreatedMs']=$now; $s['challengeExpiresMs']=$expires; $s['challenge']=$challenge; $s['expected']=$expected; return ['state'=>$s,'result'=>$challenge]; });
    }
    public function solve(string $sessionToken, string $binding, string $challengeId, array $answer, ?string $workNonce = null): array
    {
        $id = $this->sessionId($sessionToken); $now = self::now();
        return $this->store->transaction($id, 900, function (?array $s) use ($now,$binding,$challengeId,$answer,$workNonce): array { $s=$this->active($s,$binding,$now); $policy=Policy::fromArray($s['policy']); if ($s['state']!=='challenge' || !hash_equals((string)($s['challenge']['id']??''),$challengeId)) throw new CaptchaException('invalid_challenge',409); if ($now >= $s['challengeExpiresMs']) throw new CaptchaException('challenge_expired',410); if ($s['attempts'] >= $policy->maxAttempts) throw new CaptchaException('session_locked',429); if (($now-$s['challengeCreatedMs']) < $policy->minSolveMs) throw new CaptchaException('too_early',425); $s['attempts']++; if (!$this->answerValid($s['type'],(array)$s['expected'],$answer) || !$this->workValid($s['challenge']['work']??null,$workNonce)) return ['state'=>$s,'error'=>new CaptchaException('incorrect_answer',422)]; $proof=ChallengeGenerator::token(); $proofExpires=min($s['expiresMs'],$now+($policy->proofTtlSeconds*1000)); $s['state']='solved'; $s['proofHash']=hash('sha256',$proof); $s['proofExpiresMs']=$proofExpires; $s['expected']=null; $s['challenge']=null; return ['state'=>$s,'result'=>['token'=>$s['id'].'.'.$proof,'expiresInMs'=>$proofExpires-$now]]; });
    }
    public function consume(string $proofToken, string $action, string $binding): bool
    {
        $this->validateBinding($binding); $parts=explode('.',$proofToken); if (count($parts)!==2 || !preg_match('/^[a-f0-9]{64}$/D',$parts[0]) || !$this->validRawToken($parts[1])) throw new CaptchaException('invalid_proof',403); $now=self::now();
        return $this->store->transaction($parts[0],900,function(?array $s) use($parts,$action,$binding,$now): array { if ($s===null || $s['state']!=='solved' || $s['action']!==$action || !hash_equals($s['binding'],$this->bind($binding)) || !hash_equals($s['proofHash'],hash('sha256',$parts[1])) || $now >= $s['proofExpiresMs'] || $now >= $s['expiresMs'] || !$this->policyActive($s)) throw new CaptchaException('invalid_proof',403); $s['state']='consumed'; $s['proofHash']=''; return ['state'=>$s,'result'=>true]; });
    }
    public function actionExists(string $action): bool { return isset($this->actions[$action]); }
    private function active(?array $s,string $binding,int $now): array { if ($s===null || !$this->policyActive($s) || $now >= $s['expiresMs'] || $now < $s['createdMs'] || !hash_equals($s['binding'],$this->bind($binding))) throw new CaptchaException('session_expired',410); return $s; }
    private function policyActive(array $s): bool { return isset($this->actions[$s['action']]) && $this->actions[$s['action']]->toArray() === $s['policy']; }
    private function policy(string $action): Policy { return $this->actions[$action] ?? throw new CaptchaException('invalid_context',400); }
    private function bind(string $binding): string { return hash_hmac('sha256',"jervis.binding.v1\0{$binding}",$this->secret); }
    private function validateBinding(string $binding): void { if (strlen($binding)<16 || strlen($binding)>256) throw new CaptchaException('invalid_context',400); }
    private function sessionId(string $token): string { if (!$this->validRawToken($token)) throw new CaptchaException('invalid_session',400); return hash('sha256',$token); }
    private function validRawToken(string $token): bool { return strlen($token)===43 && preg_match('/^[A-Za-z0-9_-]{43}$/D',$token)===1; }
    private static function now(): int { return (int) floor(microtime(true)*1000); }
    private function answerValid(string $type,array $want,array $got): bool
    {
        if ($type==='pow') return true; if (in_array($type,['text','math','logic'],true)) return hash_equals($this->normalize((string)($want['text']??'')),$this->normalize((string)($got['text']??''))); if ($type==='slider') return is_numeric($got['x']??null) && abs((float)$want['x']-(float)$got['x'])<=5; if ($type==='drag') return is_numeric($got['x']??null) && is_numeric($got['y']??null) && hypot((float)$want['x']-(float)$got['x'],(float)$want['y']-(float)$got['y'])<=6; if ($type==='rotate') { if (!is_numeric($got['angle']??null)) return false; $angle=(float)$got['angle']; if ($angle<0 || $angle>=360) return false; $d=abs((float)$want['angle']-$angle); return min($d,360-$d)<=5; } if ($type==='click') { $w=$want['points']??[]; $g=$got['points']??[]; if (!is_array($g) || count($w)!==count($g)) return false; foreach($w as $i=>$point) if (!isset($g[$i]['x'],$g[$i]['y']) || hypot((float)$point['x']-(float)$g[$i]['x'],(float)$point['y']-(float)$g[$i]['y'])>19) return false; return true; } return false;
    }
    private function normalize(string $value): string { return strtoupper(trim(strtr($value,['٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9','۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9']))); }
    private function workValid(?array $work,?string $nonce): bool { if ($work===null) return ($nonce??'')===''; if ($nonce===null || preg_match('/^\d{1,8}$/D',$nonce)!==1) return false; $bits=(int)($work['bits']??0); if ($bits<1 || $bits>20) return false; $hash=hash('sha256',(string)$work['seed'].':'.$nonce,true); for($i=0;$i<$bits;$i++) if ((ord($hash[intdiv($i,8)]) & (1 << (7-($i%8)))) !== 0) return false; return true; }
}
