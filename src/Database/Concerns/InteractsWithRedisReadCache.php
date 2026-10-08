<?php

namespace Paimis\RedisReadCache\Database\Concerns;

use Paimis\RedisReadCache\Services\RedisReadCacheService;

trait InteractsWithRedisReadCache
{
    protected function redisReadCache(): RedisReadCacheService
    {
        return app(RedisReadCacheService::class);
    }

    protected function shouldServeFromReadCache(string $query): bool
    {
        $service = $this->redisReadCache();

        return $service->enabled() && $service->shouldCacheRead($query);
    }

    public function select($query, $bindings = [], $useReadPdo = true)
    {
        if (! $this->shouldServeFromReadCache($query)) {
            return parent::select($query, $bindings, $useReadPdo);
        }

        $cacheKey = $this->redisReadCache()->buildKey(
            $this->getName(),
            $this->getDatabaseName(),
            $query,
            $bindings
        );

        $cachedResult = $this->redisReadCache()->get($cacheKey);

        if ($cachedResult !== null) {
            return $cachedResult;
        }

        $result = parent::select($query, $bindings, $useReadPdo);

        $this->redisReadCache()->put($cacheKey, $result);

        return $result;
    }

    public function cursor($query, $bindings = [], $useReadPdo = true)
    {
        if (! $this->shouldServeFromReadCache($query)) {
            foreach (parent::cursor($query, $bindings, $useReadPdo) as $row) {
                yield $row;
            }

            return;
        }

        $rows = $this->select($query, $bindings, $useReadPdo);

        foreach ($rows as $row) {
            yield $row;
        }
    }

    public function statement($query, $bindings = [])
    {
        $result = parent::statement($query, $bindings);

        $this->redisReadCache()->invalidateAll();

        return $result;
    }

    public function affectingStatement($query, $bindings = [])
    {
        $result = parent::affectingStatement($query, $bindings);

        $this->redisReadCache()->invalidateAll();

        return $result;
    }
}
