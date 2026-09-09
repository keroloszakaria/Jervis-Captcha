<?php
declare(strict_types=1);
namespace Jervis\Captcha\Engine;
use Jervis\Captcha\Contracts\Store;
use RuntimeException;
final class MemoryStore implements Store
{
    private array $items=[]; private array $limits=[];
    public function create(string $id,array $state,int $ttlSeconds): void { if(isset($this->items[$id])) throw new RuntimeException('duplicate session'); $this->items[$id]=$state; }
    public function get(string $id): ?array { return $this->items[$id]??null; }
    public function transaction(string $id,int $ttlSeconds,callable $callback): mixed { $result=$callback($this->items[$id]??null); if(isset($result['state'])) $this->items[$id]=$result['state']; if(isset($result['error']) && $result['error'] instanceof \Throwable) throw $result['error']; return $result['result']??null; }
    public function rateLimit(string $key,int $limit,int $windowSeconds): bool { $bucket=intdiv(time(),$windowSeconds); $k=$key.':'.$bucket; $this->limits[$k]=($this->limits[$k]??0)+1; return $this->limits[$k] <= $limit; }
    public function ping(): bool { return true; }
}
