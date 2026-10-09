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

    public function shouldCacheReadFromCaller(array $trace, string $query = ''): bool
    {
        if (! $this->controllerScopeEnabled()) {
            return true;
        }

        $scope = $this->config['controller_scope'] ?? [];
        $controllerPaths = array_map(
            fn (string $path): string => $this->normalizePath($path),
            $scope['paths'] ?? []
        );
        $packagePath = $this->normalizePath(dirname(__DIR__, 2));
        $excludedClasses = $scope['exclude_classes'] ?? [];
        $includedModelTable = $this->queryUsesIncludedTable($query, $scope['include_tables'] ?? []);

        foreach ($trace as $frame) {
            $class = $frame['class'] ?? null;

            if (
                is_string($class)
                && $this->matchesExcludedClass($class, $excludedClasses)
                && ! $includedModelTable
            ) {
                return false;
            }

            $file = $frame['file'] ?? null;

            if (! is_string($file) || $file === '') {
                continue;
            }

            $file = $this->normalizePath($file);

            if ($this->isWithinPath($file, $packagePath) || $this->isVendorPath($file)) {
                continue;
            }

            foreach ($controllerPaths as $controllerPath) {
                if ($this->isWithinPath($file, $controllerPath)) {
                    return true;
                }
            }

            return false;
        }

        return false;
    }

    public function controllerScopeEnabled(): bool
    {
        return (bool) ($this->config['controller_scope']['enabled'] ?? false);
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

    public function cacheSelectResult(
        string $connectionName,
        string $databaseName,
        string $query,
        array $bindings,
        array $result
    ): bool {
        if (! $this->enabled() || ! $this->shouldCacheRead($query)) {
            return false;
        }

        return $this->put(
            $this->buildKey($connectionName, $databaseName, $query, $bindings),
            $result
        );
    }

    public function redisAvailable(): bool
    {
        $redis = $this->redisConnection();

        if ($redis === null) {
            return false;
        }

        try {
            $response = $redis->ping();

            return $response === true || in_array(strtoupper((string) $response), ['PONG', '1'], true);
        } catch (Throwable $exception) {
            Log::warning('Redis read cache ping failed.', ['exception' => $exception->getMessage()]);

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

    protected function normalizePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);

        if (! preg_match('/^(?:[A-Za-z]:\/|\/)/', $path)) {
            $path = getcwd().'/'.$path;
        }

        $path = rtrim($path, '/');

        return PHP_OS_FAMILY === 'Windows' ? strtolower($path) : $path;
    }

    protected function isWithinPath(string $path, string $directory): bool
    {
        return $path === $directory || str_starts_with($path, $directory.'/');
    }

    protected function isVendorPath(string $path): bool
    {
        return str_contains($path, '/vendor/');
    }

    protected function matchesExcludedClass(string $class, array $excludedClasses): bool
    {
        foreach ($excludedClasses as $excludedClass) {
            if (
                is_string($excludedClass)
                && $excludedClass !== ''
                && is_a($class, $excludedClass, true)
            ) {
                return true;
            }
        }

        return false;
    }

    protected function queryUsesIncludedTable(string $query, array $includedTables): bool
    {
        foreach ($includedTables as $table) {
            if (! is_string($table) || $table === '') {
                continue;
            }

            $identifier = '[`"\[]?'.preg_quote($table, '/').'[`"\]]?';
            $pattern = '/\b(?:from|join)\s+(?:(?:[`"\[]?[\w$]+[`"\]]?)\s*\.\s*)?'.$identifier.'(?![\w$])/i';

            if (preg_match($pattern, $query)) {
                return true;
            }
        }

        return false;
    }
}
