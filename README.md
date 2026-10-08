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

## Packagist

This package is designed to be publishable to Packagist later as:

```text
kybdev/laravel-redis-read-cache
```

## License

MIT
