<?php

namespace KybDev\RedisReadCache\Services;

use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Support\Facades\Log;
use Throwable;

class RedisReadCacheService
{
    public function __construct(
        protected RedisFactory $redis,
        protected array $config = []
    ) {}

    public function enabled(): bool
    {
        return (bool) ($this->config['enabled'] ?? false);
    }

    public function shouldCacheRead(string $query): bool
    {
        $trimmed = ltrim($query);

        if (! preg_match('/^SELECT\b/i', $trimmed)) {
            return false;
        }

        if (preg_match('/\bINTO\s+OUTFILE\b/i', $trimmed)) {
            return false;
        }

        return true;
    }

    public function buildKey(string $connectionName, string $databaseName, string $query, array $bindings = []): string
    {
        $payload = [
            'connection' => $connectionName,
            'database' => $databaseName,
            'sql' => $query,
            'bindings' => $bindings,
        ];

        return $this->cachePrefix().hash('sha256', serialize($payload));
    }

    public function get(string $key): mixed
    {
        $redis = $this->redisConnection();

        if ($redis === null) {
            return null;
        }

        try {
            $cached = $redis->get($key);

            if ($cached === false || $cached === null) {
                return null;
            }

            $unserialized = @unserialize((string) $cached);

            return $unserialized === false ? null : $unserialized;
        } catch (Throwable $exception) {
            Log::warning('Redis read cache get failed.', ['key' => $key, 'exception' => $exception->getMessage()]);

            return null;
        }
    }

    public function put(string $key, mixed $value): bool
    {
        $redis = $this->redisConnection();

        if ($redis === null) {
            return false;
        }

        try {
            return (bool) $redis->setex($key, $this->ttl(), serialize($value));
        } catch (Throwable $exception) {
            Log::warning('Redis read cache put failed.', ['key' => $key, 'exception' => $exception->getMessage()]);

            return false;
        }
    }

    public function invalidateAll(): void
    {
        $redis = $this->redisConnection();

        if ($redis === null) {
            return;
        }

        try {
            $keys = $redis->keys($this->cachePrefix().'*');

            if (! empty($keys)) {
                $redis->del($keys);
            }
        } catch (Throwable $exception) {
            Log::warning('Redis read cache invalidation failed.', ['exception' => $exception->getMessage()]);
        }
    }

    protected function redisConnection(): mixed
    {
        try {
            return $this->redis->connection($this->config['connection'] ?? 'cache');
        } catch (Throwable $exception) {
            Log::warning('Redis read cache connection unavailable.', ['connection' => $this->config['connection'] ?? 'cache', 'exception' => $exception->getMessage()]);

            return null;
        }
    }

    protected function cachePrefix(): string
    {
        $prefix = trim((string) ($this->config['prefix'] ?? 'redis_read_cache:'), ':');

        return $prefix.':';
    }

    protected function ttl(): int
    {
        return max(1, (int) ($this->config['ttl'] ?? 300));
    }
}
