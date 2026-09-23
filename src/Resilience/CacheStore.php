<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Resilience;

use Closure;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository;
use LonelyLights\Prosetta\Exceptions\ProsettaException;
use Psr\SimpleCache\InvalidArgumentException;

/**
 * The cache the resilience layer keeps its circuits, budgets and suspended
 * runs in. It reports cache problems as ProsettaExceptions, and it takes
 * locks from the store's LockProvider, so a store that can't lock fails
 * with a clear message instead of an undefined method.
 */
final readonly class CacheStore {
    public function __construct(private Repository $cache) {}

    public function get(string $key, mixed $default = null): mixed {
        try {
            return $this->cache->get($key, $default);
        } catch (InvalidArgumentException $e) {
            throw new ProsettaException("Invalid cache key [$key].", 0, $e);
        }
    }

    public function forever(string $key, mixed $value): void {
        $this->cache->forever($key, $value);
    }

    public function forget(string $key): void {
        $this->cache->forget($key);
    }

    public function add(string $key, mixed $value, int $seconds): bool {
        return $this->cache->add($key, $value, $seconds);
    }

    public function increment(string $key, int $by): int {
        return (int) $this->cache->increment($key, $by);
    }

    public function lock(string $name, int $seconds = 0): Lock {
        $store = $this->cache->getStore();

        if (! $store instanceof LockProvider) {
            throw new ProsettaException("Prosetta's resilience cache store can't take locks; use redis, database, file, memcached or dynamodb.");
        }

        return $store->lock($name, $seconds);
    }

    /**
     * Runs $change while holding lock $name, waiting up to $wait seconds for it.
     *
     * @template T
     * @param Closure(): T $change
     * @return T
     */
    public function locked(string $name, int $seconds, int $wait, Closure $change): mixed {
        try {
            return $this->lock($name, $seconds)->block($wait, $change);
        } catch (LockTimeoutException $e) {
            throw new ProsettaException("Timed out after $wait s waiting for the lock [$name].", 0, $e);
        }
    }
}
