<?php

namespace Paimis\RedisReadCache;

use Illuminate\Database\Connection;
use Illuminate\Support\ServiceProvider;
use Paimis\RedisReadCache\Database\RedisReadThroughMySqlConnection;
use Paimis\RedisReadCache\Database\RedisReadThroughPostgresConnection;
use Paimis\RedisReadCache\Database\RedisReadThroughSqlServerConnection;
use Paimis\RedisReadCache\Database\RedisReadThroughSqliteConnection;
use Paimis\RedisReadCache\Services\RedisReadCacheService;

class RedisReadCacheServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/redis-read-cache.php', 'redis.read_cache');

        $this->app->singleton(RedisReadCacheService::class, function ($app) {
            return new RedisReadCacheService($app['redis'], config('redis.read_cache', []));
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/redis-read-cache.php' => config_path('redis-read-cache.php'),
            ], 'redis-read-cache-config');
        }

        if (! config('redis.read_cache.enabled', false)) {
            return;
        }

        $drivers = config('redis.read_cache.drivers', ['sqlsrv', 'mysql', 'pgsql', 'sqlite']);

        foreach ($drivers as $driver) {
            $className = match ($driver) {
                'sqlsrv' => RedisReadThroughSqlServerConnection::class,
                'mysql' => RedisReadThroughMySqlConnection::class,
                'pgsql' => RedisReadThroughPostgresConnection::class,
                'sqlite' => RedisReadThroughSqliteConnection::class,
                default => null,
            };

            if ($className === null) {
                continue;
            }

            Connection::resolverFor($driver, function ($connection, $database, $prefix, $config) use ($className) {
                return new $className($connection, $database, $prefix, $config);
            });
        }
    }
}
