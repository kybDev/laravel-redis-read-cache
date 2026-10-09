<?php

namespace KybDev\RedisReadCache\Http\Controllers;

use Illuminate\Routing\Controller;
use KybDev\RedisReadCache\Services\RedisReadCacheProfiler;
use KybDev\RedisReadCache\Services\RedisReadCacheService;

class RedisReadCacheDashboardController extends Controller
{
    public function index(RedisReadCacheService $cache, RedisReadCacheProfiler $profiler)
    {
        $metrics = $cache->metrics();
        $profiles = config('redis.read_cache.profiling.enabled', false)
            ? $profiler->profiles()
            : [];

        return view('redis-read-cache::dashboard', [
            'metrics' => $metrics,
            'redisAvailable' => $cache->redisAvailable(),
            'cacheEnabled' => $cache->enabled(),
            'scopeEnabled' => $cache->controllerScopeEnabled(),
            'profilingEnabled' => $profiler->enabled(),
            'profileCount' => is_array($profiles) ? count($profiles) : 0,
            'dashboardConfig' => config('redis.read_cache.dashboard', []),
        ]);
    }
}
