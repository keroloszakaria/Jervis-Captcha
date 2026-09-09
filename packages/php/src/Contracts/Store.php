<?php
declare(strict_types=1);
namespace Jervis\Captcha\Contracts;
interface Store
{
    public function create(string $id, array $state, int $ttlSeconds): void;
    public function get(string $id): ?array;
    public function transaction(string $id, int $ttlSeconds, callable $callback): mixed;
    public function rateLimit(string $key, int $limit, int $windowSeconds): bool;
    public function ping(): bool;
}
