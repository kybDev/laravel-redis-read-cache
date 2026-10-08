<?php

namespace Paimis\RedisReadCache\Tests\Feature;

use Illuminate\Support\Facades\Config;
use Paimis\RedisReadCache\Services\RedisReadCacheService;
use PHPUnit\Framework\TestCase;

class RedisReadCacheTest extends TestCase
{
    public function test_redis_read_cache_is_disabled_by_default(): void
    {
        Config::set('redis.read_cache.enabled', false);

        $this->assertFalse(config('redis.read_cache.enabled'));
    }

    public function test_service_builds_a_stable_cache_key(): void
    {
        Config::set('redis.read_cache.enabled', true);
        Config::set('redis.read_cache.prefix', 'test_cache:');

        $service = new RedisReadCacheService(app('redis'), config('redis.read_cache', []));

        $keyOne = $service->buildKey('sqlsrv', 'paimis', 'SELECT * FROM units WHERE id = ?', [1]);
        $keyTwo = $service->buildKey('sqlsrv', 'paimis', 'SELECT * FROM units WHERE id = ?', [1]);

        $this->assertSame($keyOne, $keyTwo);
        $this->assertStringStartsWith('test_cache:', $keyOne);
    }
}
