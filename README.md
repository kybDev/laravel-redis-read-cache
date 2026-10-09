# Laravel Redis Read Cache

A Laravel package that provides a transparent Redis read-through cache for eligible database reads without changing controllers, models, queries, or application business logic.

## Compatibility

- PHP 8.2 and 8.3
- Laravel 10, 11, and 12 (through the matching `illuminate/*` components)

The package supports Laravel 10.0+ through 12.x. It requires PHP `^8.2`.
The service provider is registered through Laravel package auto-discovery.

## Features

- Global read-through interception at the Laravel database connection layer
- Environment-controlled activation via `REDIS_READ_CACHE_ENABLED`
- Uses the configured Laravel Redis connection
- Falls back safely to normal database reads when Redis is unavailable
- Optionally limits caching to direct query-builder reads in configured controller directories
- Reuses repeated cached query results in request-local memory to avoid duplicate Redis round trips
- Profiles slow pages and repeated SELECT query patterns and reports cache candidates without changing cache policy
- Includes a warm-up command for newly provisioned servers

## Installation

```bash
composer require kybdev/laravel-redis-read-cache
```

If you want to publish the package config file, run:

```bash
php artisan vendor:publish --provider="KybDev\\RedisReadCache\\RedisReadCacheServiceProvider" --tag=redis-read-cache-config
```

Add the following environment variables:

```env
REDIS_READ_CACHE_ENABLED=true
REDIS_READ_CACHE_CONNECTION=cache
REDIS_READ_CACHE_TTL=300
REDIS_READ_CACHE_PREFIX=redis_read_cache:
```

To cache only direct `DB::table()` or query-builder reads executed in controller files, enable the controller scope:

```env
REDIS_READ_CACHE_CONTROLLER_SCOPE=true
REDIS_READ_CACHE_CONTROLLER_PATHS=app/Http/Controllers
```

You can provide multiple comma-separated directories. When this scope is enabled, Eloquent model queries and reads whose first application caller is outside those directories (such as services or repositories) bypass the cache. Eloquent builders/models are excluded by default. To allow a specific model table while continuing to exclude other models, set a table allowlist:

```env
REDIS_READ_CACHE_CONTROLLER_INCLUDE_TABLES=access_controls
```

Multiple table names may be supplied as a comma-separated list. Only queries originating in the configured controller directories and referencing an included table in a `FROM` or `JOIN` clause bypass the default Eloquent exclusion. You can also customize the `controller_scope.exclude_classes` array in the published config. The package inspects the PHP call stack for scoped reads, which adds some overhead; leave the scope disabled if transparent caching of all eligible `SELECT` statements is preferred.

Repeated identical queries within one Laravel request/job reuse a request-scoped in-memory result after the first Redis lookup. This avoids Redis round trips for duplicate reads in the same lifecycle; the first read still pays Redis lookup and deserialization costs. Local reuse is bounded to 64 entries of up to 256 KiB each to avoid retaining unbounded result sets in memory. Cache writes clear the local results. The service uses Laravel's scoped lifetime so this in-memory optimization does not leak results between requests in long-running workers.

## Profiling and cache recommendations

Profiling is opt-in and independent of `REDIS_READ_CACHE_ENABLED`. It records route duration and SELECT query counts/durations to the configured Redis connection. It stores normalized SQL shapes only: binding values and quoted/numeric literal values are not included. Profiling data is retained for seven days by default.

```env
REDIS_READ_CACHE_PROFILING_ENABLED=true
REDIS_READ_CACHE_PROFILING_CONNECTION=cache
REDIS_READ_CACHE_PROFILING_TTL=604800
REDIS_READ_CACHE_PROFILING_SAMPLE_RATE=1
```

For a clean database baseline, temporarily disable read-through caching while profiling. Cached hits do not dispatch database query events, so profiling while caching is active observes database misses, not the reads already served by Redis. Collect representative traffic, then review recommendations:

```bash
php artisan redis:profile
php artisan redis:profile --limit=50
```

The report ranks routes by average response time and query patterns by cumulative database time. A query is recommended only when it meets the configured minimum execution count and either the cumulative-time or per-execution slow-query threshold. A page is reported only after the minimum request count and average page-time threshold. Thresholds can be adjusted under `redis.read_cache.profiling` in the published config. Profiling only reports candidates; it never enables caching or changes the cache allowlist automatically. Review freshness, user/tenant isolation, and invalidation requirements before adding a candidate to a cache policy.

Profiling adds query-event aggregation and a Redis pipeline at request completion, so sample production traffic carefully. Reduce `REDIS_READ_CACHE_PROFILING_SAMPLE_RATE` (for example `0.1`) for lower overhead. Disable profiling after collecting enough data. When profiling is enabled but Redis is unavailable, the application continues serving requests and logs a warning; `redis:profile` reports a connection failure.

## Usage

This package works transparently. Existing usage such as:

```php
$units = Unit::where('active', 1)->get();
```

automatically uses Redis when enabled.

## Warm-up command

```bash
php artisan redis:hot-runner
php artisan redis:hot-runner --tables=units,systems --limit=500 --force
```

The warm-up command explicitly writes its fetched `SELECT` results to Redis, so it works even when controller-only caching is enabled. It first checks Redis connectivity and exits with a failure status if Redis or any cache write fails.

## Packagist

This package is designed to be publishable to Packagist later as:

```text
kybdev/laravel-redis-read-cache
```

## License

MIT
