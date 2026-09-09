<?php
declare(strict_types=1);

namespace Jervis\Captcha\Laravel\Storage;

use Illuminate\Cache\CacheManager;
use Illuminate\Cache\DatabaseStore;
use Illuminate\Cache\FileStore;
use Illuminate\Cache\RedisStore;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Jervis\Captcha\Contracts\Store;
use RuntimeException;

final class LaravelCacheStore implements Store
{
    private const LOCK_SECONDS = 15;
    private const LOCK_WAIT_SECONDS = 2;
    private const FAST_LOCK_RETRY_MILLISECONDS = 10;
    private const FILE_LOCK_RETRY_MILLISECONDS = 250;

    public function __construct(
        private readonly CacheManager $cache,
        private readonly ?string $storeName = null,
        private readonly string $prefix = 'jervis-captcha:',
    ) {}

    private function repository() { return $this->cache->store($this->storeName); }
    private function key(string $key): string { return $this->prefix . $key; }

    private function blockingLock(object $repository, string $name): object
    {
        $lock = $repository->lock($name, self::LOCK_SECONDS);
        $store = method_exists($repository, 'getStore') ? $repository->getStore() : null;
        if (method_exists($lock, 'betweenBlockedAttemptsSleepFor')) {
            if ($store instanceof RedisStore || $store instanceof DatabaseStore) {
                $lock->betweenBlockedAttemptsSleepFor(self::FAST_LOCK_RETRY_MILLISECONDS);
            } elseif ($store instanceof FileStore) {
                $lock->betweenBlockedAttemptsSleepFor(self::FILE_LOCK_RETRY_MILLISECONDS);
            }
        }
        return $lock;
    }

    private function putOrFail(object $repository, string $key, mixed $value, int $ttlSeconds): void
    {
        if ($repository->put($key, $value, $ttlSeconds) !== true) {
            throw new RuntimeException('CAPTCHA state persistence failed');
        }
    }

    public function create(string $id, array $state, int $ttlSeconds): void
    {
        $repo = $this->repository();
        $key = $this->key('session:' . $id);
        try {
            $this->blockingLock($repo, $key . ':lock')->block(self::LOCK_WAIT_SECONDS, function () use ($repo, $key, $state, $ttlSeconds): void {
                if ($repo->has($key)) throw new RuntimeException('duplicate CAPTCHA session');
                $this->putOrFail($repo, $key, $state, $ttlSeconds);
            });
        } catch (LockTimeoutException $e) {
            throw new RuntimeException('CAPTCHA state lock unavailable', 0, $e);
        }
    }

    public function get(string $id): ?array
    {
        $value = $this->repository()->get($this->key('session:' . $id));
        return is_array($value) ? $value : null;
    }

    public function transaction(string $id, int $ttlSeconds, callable $callback): mixed
    {
        $repo = $this->repository();
        $key = $this->key('session:' . $id);
        try {
            return $this->blockingLock($repo, $key . ':lock')->block(self::LOCK_WAIT_SECONDS, function () use ($repo, $key, $ttlSeconds, $callback): mixed {
                $state = $repo->get($key);
                $result = $callback(is_array($state) ? $state : null);
                if (!is_array($result)) throw new RuntimeException('Invalid CAPTCHA state transaction result');
                if (isset($result['state'])) {
                    $next = $result['state'];
                    if (!is_array($next)) throw new RuntimeException('Invalid CAPTCHA state');
                    $remaining = max(1, (int) ceil((((int) ($next['expiresMs'] ?? 0)) - (int) floor(microtime(true) * 1000)) / 1000));
                    $this->putOrFail($repo, $key, $next, min($ttlSeconds, $remaining));
                }
                if (isset($result['error']) && $result['error'] instanceof \Throwable) throw $result['error'];
                return $result['result'] ?? null;
            });
        } catch (LockTimeoutException $e) {
            throw new RuntimeException('CAPTCHA state lock unavailable', 0, $e);
        }
    }

    public function rateLimit(string $key, int $limit, int $windowSeconds): bool
    {
        $repo = $this->repository();
        $bucket = intdiv(time(), $windowSeconds);
        $cacheKey = $this->key('limit:' . hash('sha256', $key) . ':' . $bucket);
        try {
            return $this->blockingLock($repo, $cacheKey . ':lock')->block(self::LOCK_WAIT_SECONDS, function () use ($repo, $cacheKey, $limit, $windowSeconds): bool {
                $count = (int) $repo->get($cacheKey, 0) + 1;
                $this->putOrFail($repo, $cacheKey, $count, $windowSeconds + 2);
                return $count <= $limit;
            });
        } catch (LockTimeoutException) {
            return false;
        }
    }

    public function ping(): bool
    {
        $repo = $this->repository();
        $key = $this->key('doctor:' . bin2hex(random_bytes(8)));
        $lock = null;
        $acquired = false;
        try {
            $lock = $repo->lock($key . ':lock', self::LOCK_SECONDS);
            $acquired = $lock->get();
            if (!$acquired) return false;
            if ($repo->put($key, 'ok', 5) !== true) return false;
            return $repo->get($key) === 'ok';
        } catch (\Throwable) {
            return false;
        } finally {
            if ($acquired && $lock !== null) {
                try { $lock->release(); } catch (\Throwable) {}
            }
            try { $repo->forget($key); } catch (\Throwable) {}
        }
    }
}
