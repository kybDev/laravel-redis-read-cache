<?php

namespace KybDev\RedisReadCache\Services;

use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Log;
use Throwable;

class RedisReadCacheProfiler
{
    protected array $queries = [];

    protected int $recordedQueries = 0;

    protected bool $flushed = false;

    public function __construct(
        protected RedisFactory $redis,
        protected array $config = []
    ) {}

    public function enabled(): bool
    {
        return (bool) ($this->config['enabled'] ?? false);
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
            Log::warning('Redis read-cache profiler ping failed.', [
                'exception' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    public function routeIdentifier(object $request): string
    {
        $route = method_exists($request, 'route') ? $request->route() : null;
        $routeName = is_object($route) && method_exists($route, 'getName')
            ? $route->getName()
            : null;

        if (is_string($routeName) && $routeName !== '') {
            return $routeName;
        }

        $method = method_exists($request, 'method') ? strtoupper($request->method()) : 'UNKNOWN';
        $uri = is_object($route) && method_exists($route, 'uri')
            ? $route->uri()
            : 'unmatched';

        return $method.' '.$uri;
    }

    public function record(QueryExecuted $event, string $route): void
    {
        if (
            ! $this->enabled()
            || ! $this->isSelect($event->sql)
            || $this->recordedQueries >= max(1, (int) ($this->config['max_records'] ?? 1000))
        ) {
            return;
        }

        $sampleRate = min(1, max(0, (float) ($this->config['sample_rate'] ?? 1)));

        if ($sampleRate <= 0 || ($sampleRate < 1 && mt_rand() / mt_getrandmax() > $sampleRate)) {
            return;
        }

        $normalizedSql = $this->normalizeSql($event->sql);
        $identity = $event->connectionName."\0".$event->connection->getDatabaseName()."\0".$normalizedSql;
        $fingerprint = hash('sha256', $identity);
        $key = $route."\0".$fingerprint;
        $duration = max(0, (float) $event->time);

        if (! isset($this->queries[$key])) {
            $this->queries[$key] = [
                'route' => $route,
                'fingerprint' => $fingerprint,
                'connection' => (string) $event->connectionName,
                'query' => $normalizedSql,
                'calls' => 0,
                'total_ms' => 0.0,
            ];
        }

        $this->queries[$key]['calls']++;
        $this->queries[$key]['total_ms'] += $duration;
        $this->recordedQueries++;
    }

    public function flush(object $request): bool
    {
        if (! $this->enabled() || $this->flushed) {
            return true;
        }

        $this->flushed = true;
        $redis = $this->redisConnection();

        if ($redis === null) {
            return false;
        }

        $route = $this->routeIdentifier($request);
        $start = method_exists($request, 'server')
            ? $request->server('REQUEST_TIME_FLOAT')
            : null;
        $pageDuration = is_numeric($start) && (float) $start > 0
            ? max(0, (microtime(true) - (float) $start) * 1000)
            : 0.0;
        $index = $this->indexKey();
        $ttl = max(60, (int) ($this->config['ttl'] ?? 604800));
        $maxRecords = max(1, (int) ($this->config['max_records'] ?? 1000));
        $profiles = [];

        foreach ($this->queries as $query) {
            $queryKey = $this->profileKey('query', hash('sha256', $query['route']."\0".$query['fingerprint']));
            $profiles[$queryKey] = $query + ['type' => 'query'];
        }

        $routeKey = $this->profileKey('page', hash('sha256', $route));
        $profiles[$routeKey] = ['type' => 'page', 'route' => $route];

        try {
            $knownKeys = $redis->smembers($index);
            $knownKeys = is_array($knownKeys) ? $knownKeys : [];
            $newKeys = array_values(array_diff(array_keys($profiles), $knownKeys));

            if (count($knownKeys) + count($newKeys) > $maxRecords) {
                $available = max(0, $maxRecords - count($knownKeys));
                $allowedNewKeys = array_flip(array_slice($newKeys, 0, $available));
                if (count($newKeys) > $available) {
                    Log::notice('Redis read-cache profiler reached its configured record limit.', [
                        'max_records' => $maxRecords,
                    ]);
                }

                $profiles = array_filter(
                    $profiles,
                    fn (array $profile, string $key): bool => in_array($key, $knownKeys, true)
                        || isset($allowedNewKeys[$key]),
                    ARRAY_FILTER_USE_BOTH
                );
            }

            $redis->pipeline(function ($pipeline) use ($profiles, $index, $ttl, $routeKey, $pageDuration): void {
                foreach ($profiles as $key => $profile) {
                    $pipeline->sadd($index, $key);
                    $pipeline->expire($key, $ttl);

                    if ($profile['type'] === 'page') {
                        $pipeline->hincrby($key, 'requests', 1);
                        $pipeline->hincrbyfloat($key, 'total_ms', $pageDuration);
                        $pipeline->hset($key, 'route', $profile['route']);
                        $pipeline->hincrby($key, 'slow_requests', $pageDuration >= (float) ($this->config['slow_page_ms'] ?? 1000) ? 1 : 0);

                        continue;
                    }

                    $pipeline->hincrby($key, 'executions', $profile['calls']);
                    $pipeline->hincrby($key, 'requests', 1);
                    $pipeline->hincrbyfloat($key, 'total_ms', $profile['total_ms']);
                    $pipeline->hset($key, 'route', $profile['route']);
                    $pipeline->hset($key, 'connection', $profile['connection']);
                    $pipeline->hset($key, 'query', $profile['query']);
                }

                $pipeline->expire($index, $ttl);
            });

            return true;
        } catch (Throwable $exception) {
            Log::warning('Redis read-cache profiling flush failed.', [
                'exception' => $exception->getMessage(),
                'route' => $route,
            ]);

            return false;
        }
    }

    public function profiles(): ?array
    {
        $redis = $this->redisConnection();

        if ($redis === null) {
            return [];
        }

        try {
            $keys = $redis->smembers($this->indexKey());
            $profiles = [];

            foreach (is_array($keys) ? $keys : [] as $key) {
                $profile = $redis->hgetall($key);

                if (is_array($profile) && $profile !== []) {
                    $profile['type'] = str_starts_with($key, $this->profilePrefix().'page:')
                        ? 'page'
                        : 'query';
                    $profiles[] = $profile;
                } else {
                    $redis->srem($this->indexKey(), $key);
                }
            }

            return $profiles;
        } catch (Throwable $exception) {
            Log::warning('Redis read-cache profiling report read failed.', [
                'exception' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    protected function normalizeSql(string $sql): string
    {
        $sql = preg_replace('/\/\*.*?\*\/|--[^\r\n]*|#[^\r\n]*/s', ' ', $sql) ?? $sql;
        $sql = preg_replace('/\$(?:[A-Za-z_][A-Za-z0-9_]*)?\$.*?\$(?:[A-Za-z_][A-Za-z0-9_]*)?\$/s', '?', $sql) ?? $sql;
        $sql = preg_replace('/\b0[xX][0-9a-fA-F]+\b|\b0[bB][01]+\b/', '?', $sql) ?? $sql;
        $sql = preg_replace("/'(?:''|\\\\.|[^'])*'/s", '?', $sql) ?? $sql;
        $sql = preg_replace('/"(?:\"\"|\\\\.|[^"])*"/s', '?', $sql) ?? $sql;
        $sql = preg_replace('/\b\d+(?:\.\d+)?\b/', '?', $sql) ?? $sql;

        return strtolower(trim(preg_replace('/\s+/', ' ', $sql) ?? $sql));
    }

    protected function isSelect(string $sql): bool
    {
        return preg_match('/^\s*select\b/i', $sql) === 1
            && preg_match('/\binto\s+outfile\b/i', $sql) !== 1;
    }

    protected function redisConnection(): mixed
    {
        try {
            return $this->redis->connection($this->config['connection'] ?? 'cache');
        } catch (Throwable $exception) {
            Log::warning('Redis read-cache profiler connection unavailable.', [
                'connection' => $this->config['connection'] ?? 'cache',
                'exception' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    protected function profilePrefix(): string
    {
        return rtrim((string) ($this->config['prefix'] ?? 'redis_read_cache_profile:'), ':').':';
    }

    protected function indexKey(): string
    {
        return $this->profilePrefix().'index';
    }

    protected function profileKey(string $type, string $fingerprint): string
    {
        return $this->profilePrefix().$type.':'.$fingerprint;
    }
}
