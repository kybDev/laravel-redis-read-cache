<?php

namespace KybDev\RedisReadCache;

use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use KybDev\RedisReadCache\Console\Commands\RedisHotRunnerCommand;
use KybDev\RedisReadCache\Console\Commands\RedisReadCacheProfileCommand;
use KybDev\RedisReadCache\Database\RedisReadThroughMySqlConnection;
use KybDev\RedisReadCache\Database\RedisReadThroughPostgresConnection;
use KybDev\RedisReadCache\Database\RedisReadThroughSqlServerConnection;
use KybDev\RedisReadCache\Database\RedisReadThroughSqliteConnection;
use KybDev\RedisReadCache\Services\RedisReadCacheService;
use KybDev\RedisReadCache\Services\RedisReadCacheProfiler;
use KybDev\RedisReadCache\Http\Controllers\RedisReadCacheDashboardController;

class RedisReadCacheServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/redis-read-cache.php', 'redis.read_cache');

        $this->app->scoped(RedisReadCacheService::class, function ($app) {
            return new RedisReadCacheService($app['redis'], config('redis.read_cache', []));
        });

        $this->app->scoped(RedisReadCacheProfiler::class, function ($app) {
            return new RedisReadCacheProfiler($app['redis'], config('redis.read_cache.profiling', []));
        });

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'redis-read-cache');
    }

    public function boot(): void
    {
        if (config('redis.read_cache.profiling.enabled', false)) {
            DB::listen(function (QueryExecuted $query): void {
                if (! $this->app->bound('request')) {
                    return;
                }

                $request = $this->app->make('request');

                if (is_object($request)) {
                    $profiler = $this->app->make(RedisReadCacheProfiler::class);
                    $profiler->record($query, $profiler->routeIdentifier($request));
                }
            });

        }

        if (
            config('redis.read_cache.profiling.enabled', false)
            || config('redis.read_cache.dashboard.enabled', false)
        ) {
            $this->app['events']->listen(
                'Illuminate\Foundation\Http\Events\RequestHandled',
                function ($event): void {
                    if (! isset($event->request) || ! is_object($event->request)) {
                        return;
                    }

                    if (config('redis.read_cache.profiling.enabled', false)) {
                        $this->app->make(RedisReadCacheProfiler::class)->flush($event->request);
                    }

                    if (config('redis.read_cache.dashboard.enabled', false)) {
                        $this->app->make(RedisReadCacheService::class)->flushMetrics();
                    }
                }
            );
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/redis-read-cache.php' => config_path('redis-read-cache.php'),
            ], 'redis-read-cache-config');

            $this->commands([
                RedisHotRunnerCommand::class,
                RedisReadCacheProfileCommand::class,
            ]);
        }

        if (config('redis.read_cache.dashboard.enabled', false)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/dashboard.php');
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
