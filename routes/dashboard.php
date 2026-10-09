<?php

use Illuminate\Support\Facades\Route;
use KybDev\RedisReadCache\Http\Controllers\RedisReadCacheDashboardController;

$dashboard = config('redis.read_cache.dashboard', []);
$path = trim((string) ($dashboard['path'] ?? 'redis-read-cache'), '/');
$middleware = $dashboard['middleware'] ?? ['web', 'auth'];

Route::middleware($middleware)
    ->prefix($path)
    ->group(function (): void {
        Route::get('/', [RedisReadCacheDashboardController::class, 'index'])
            ->name('redis-read-cache.dashboard');
    });
