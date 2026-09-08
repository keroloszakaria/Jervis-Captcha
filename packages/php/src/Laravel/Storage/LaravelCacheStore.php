<?php
declare(strict_types=1);
namespace Jervis\Captcha\Laravel\Storage;
use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Jervis\Captcha\Contracts\Store;
use RuntimeException;
final class LaravelCacheStore implements Store
{
    public function __construct(private readonly CacheManager $cache,private readonly ?string $storeName=null,private readonly string $prefix='jervis-captcha:'){}
    private function repository(){return $this->cache->store($this->storeName);} private function key(string $key): string{return $this->prefix.$key;}
    public function create(string $id,array $state,int $ttlSeconds): void { $repo=$this->repository(); $key=$this->key('session:'.$id); $repo->lock($key.':lock',5)->block(2,function()use($repo,$key,$state,$ttlSeconds):void{if($repo->has($key))throw new RuntimeException('duplicate CAPTCHA session');$repo->put($key,$state,$ttlSeconds);}); }
    public function get(string $id): ?array{$value=$this->repository()->get($this->key('session:'.$id));return is_array($value)?$value:null;}
    public function transaction(string $id,int $ttlSeconds,callable $callback): mixed { $repo=$this->repository();$key=$this->key('session:'.$id);try{return $repo->lock($key.':lock',5)->block(2,function()use($repo,$key,$ttlSeconds,$callback):mixed{$state=$repo->get($key);$result=$callback(is_array($state)?$state:null);if(isset($result['state'])){$next=$result['state'];$remaining=max(1,(int)ceil((((int)($next['expiresMs']??0))-(int)floor(microtime(true)*1000))/1000));$repo->put($key,$next,min($ttlSeconds,$remaining));}if(isset($result['error'])&&$result['error'] instanceof \Throwable)throw $result['error'];return $result['result']??null;});}catch(LockTimeoutException $e){throw new RuntimeException('CAPTCHA state lock unavailable',0,$e);} }
    public function rateLimit(string $key,int $limit,int $windowSeconds): bool { $repo=$this->repository();$bucket=intdiv(time(),$windowSeconds);$cacheKey=$this->key('limit:'.hash('sha256',$key).':'.$bucket);try{return $repo->lock($cacheKey.':lock',5)->block(2,function()use($repo,$cacheKey,$limit,$windowSeconds):bool{$count=(int)$repo->get($cacheKey,0)+1;$repo->put($cacheKey,$count,$windowSeconds+2);return $count<=$limit;});}catch(LockTimeoutException){return false;} }
    public function ping(): bool{$key=$this->key('doctor:'.bin2hex(random_bytes(8)));$this->repository()->put($key,'ok',5);$ok=$this->repository()->get($key)==='ok';$this->repository()->forget($key);return $ok;}
}
