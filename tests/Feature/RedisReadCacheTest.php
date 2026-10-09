<?php

namespace KybDev\RedisReadCache\Tests\Feature;

use Illuminate\Contracts\Redis\Factory as RedisFactory;
use KybDev\RedisReadCache\Services\RedisReadCacheService;
use PHPUnit\Framework\TestCase;

class RedisReadCacheTest extends TestCase
{
    public function test_service_provider_and_supported_connection_classes_autoload(): void
    {
        $classes = [
            \KybDev\RedisReadCache\RedisReadCacheServiceProvider::class,
            \KybDev\RedisReadCache\Console\Commands\RedisHotRunnerCommand::class,
            \KybDev\RedisReadCache\Database\RedisReadThroughMySqlConnection::class,
            \KybDev\RedisReadCache\Database\RedisReadThroughPostgresConnection::class,
            \KybDev\RedisReadCache\Database\RedisReadThroughSqlServerConnection::class,
            \KybDev\RedisReadCache\Database\RedisReadThroughSqliteConnection::class,
        ];

        foreach ($classes as $class) {
            $this->assertTrue(class_exists($class), "Expected {$class} to autoload.");
        }
    }

    public function test_redis_read_cache_is_disabled_by_default(): void
    {
        $service = new RedisReadCacheService($this->createMock(RedisFactory::class));

        $this->assertFalse($service->enabled());
    }

    public function test_service_builds_a_stable_cache_key(): void
    {
        $service = new RedisReadCacheService(
            $this->createMock(RedisFactory::class),
            ['prefix' => 'test_cache:']
        );

        $keyOne = $service->buildKey('sqlsrv', 'test_database', 'SELECT * FROM units WHERE id = ?', [1]);
        $keyTwo = $service->buildKey('sqlsrv', 'test_database', 'SELECT * FROM units WHERE id = ?', [1]);

        $this->assertSame($keyOne, $keyTwo);
        $this->assertStringStartsWith('test_cache:', $keyOne);
    }

    public function test_only_read_only_selects_are_eligible(): void
    {
        $service = new RedisReadCacheService($this->createMock(RedisFactory::class));

        $this->assertTrue($service->shouldCacheRead('SELECT * FROM units'));
        $this->assertFalse($service->shouldCacheRead('UPDATE units SET active = 0'));
        $this->assertFalse($service->shouldCacheRead('SELECT * INTO OUTFILE "/tmp/units.csv" FROM units'));
    }

    public function test_controller_scope_is_disabled_by_default(): void
    {
        $service = new RedisReadCacheService($this->createMock(RedisFactory::class));

        $this->assertTrue($service->shouldCacheReadFromCaller([
            ['file' => '/app/Services/UnitService.php'],
        ]));
    }

    public function test_controller_scope_allows_direct_controller_queries(): void
    {
        $controllerPath = sys_get_temp_dir().'/sample-app/app/Http/Controllers';
        $service = new RedisReadCacheService(
            $this->createMock(RedisFactory::class),
            [
                'controller_scope' => [
                    'enabled' => true,
                    'paths' => [$controllerPath],
                ],
            ]
        );

        $this->assertTrue($service->shouldCacheReadFromCaller([
            ['file' => '/project/vendor/laravel/framework/src/Illuminate/Database/Connection.php'],
            ['file' => $controllerPath.'/UnitController.php'],
        ]));
    }

    public function test_controller_scope_rejects_service_and_eloquent_reads(): void
    {
        $controllerPath = sys_get_temp_dir().'/sample-app/app/Http/Controllers';
        $service = new RedisReadCacheService(
            $this->createMock(RedisFactory::class),
            [
                'controller_scope' => [
                    'enabled' => true,
                    'paths' => [$controllerPath],
                ],
            ]
        );

        $this->assertFalse($service->shouldCacheReadFromCaller([
            ['file' => '/project/app/Services/UnitService.php'],
            ['file' => $controllerPath.'/UnitController.php'],
        ]));

        $this->assertFalse($service->shouldCacheReadFromCaller([
            ['class' => \Illuminate\Database\Eloquent\Builder::class],
            ['file' => $controllerPath.'/UnitController.php'],
        ]));
    }
}
