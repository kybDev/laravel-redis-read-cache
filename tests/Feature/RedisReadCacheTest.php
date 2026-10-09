<?php

namespace KybDev\RedisReadCache\Tests\Feature;

use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Contracts\Redis\Connection as RedisConnection;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use KybDev\RedisReadCache\Services\RedisReadCacheService;
use KybDev\RedisReadCache\Services\RedisReadCacheProfiler;
use PHPUnit\Framework\TestCase;

class RedisReadCacheTest extends TestCase
{
    public function test_service_provider_and_supported_connection_classes_autoload(): void
    {
        $classes = [
            \KybDev\RedisReadCache\RedisReadCacheServiceProvider::class,
            \KybDev\RedisReadCache\Console\Commands\RedisHotRunnerCommand::class,
            \KybDev\RedisReadCache\Console\Commands\RedisReadCacheProfileCommand::class,
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
                    'exclude_classes' => [
                        \Illuminate\Database\Eloquent\Builder::class,
                        \Illuminate\Database\Eloquent\Model::class,
                    ],
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
                    'exclude_classes' => [
                        \Illuminate\Database\Eloquent\Builder::class,
                        \Illuminate\Database\Eloquent\Model::class,
                    ],
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

    public function test_controller_scope_allows_eloquent_for_explicitly_included_table(): void
    {
        $controllerPath = sys_get_temp_dir().'/sample-app/app/Http/Controllers';
        $service = new RedisReadCacheService(
            $this->createMock(RedisFactory::class),
            [
                'controller_scope' => [
                    'enabled' => true,
                    'paths' => [$controllerPath],
                    'include_tables' => ['access_controls'],
                    'exclude_classes' => [
                        \Illuminate\Database\Eloquent\Builder::class,
                        \Illuminate\Database\Eloquent\Model::class,
                    ],
                ],
            ]
        );

        $trace = [
            ['class' => \Illuminate\Database\Eloquent\Builder::class, 'file' => '/project/vendor/laravel/framework/src/Illuminate/Database/Eloquent/Builder.php'],
            ['file' => $controllerPath.'/AdminUsersController.php'],
        ];

        $this->assertTrue($service->shouldCacheReadFromCaller(
            $trace,
            'select top 1 * from [dbo].[access_controls] where [admin_user_id] = ?'
        ));
        $this->assertFalse($service->shouldCacheReadFromCaller(
            $trace,
            'select * from [dbo].[admin_users] where [id] = ?'
        ));
    }

    public function test_hot_runner_can_store_read_results_independently_of_caller_scope(): void
    {
        $service = new class($this->createMock(RedisFactory::class), [
            'enabled' => true,
            'controller_scope' => ['enabled' => true],
        ]) extends RedisReadCacheService {
            public array $stored = [];

            public function put(string $key, mixed $value, bool $warmed = false): bool
            {
                $this->stored = [$key, $value];

                return true;
            }
        };

        $rows = [(object) ['id' => 1]];

        $this->assertTrue($service->cacheSelectResult(
            'sqlsrv',
            'test_database',
            'SELECT TOP 500 * FROM units',
            [],
            $rows
        ));
        $this->assertSame($rows, $service->stored[1]);
        $this->assertStringStartsWith('redis_read_cache:', $service->stored[0]);
    }

    public function test_repeated_reads_use_request_local_result_after_first_redis_read(): void
    {
        $redis = new InMemoryRedisConnection;
        $key = 'redis_read_cache:test';
        $rows = [(object) ['id' => 1]];
        $redis->values[$key] = serialize($rows);

        $factory = $this->createMock(RedisFactory::class);
        $factory->expects($this->any())
            ->method('connection')
            ->with('cache')
            ->willReturn($redis);

        $service = new RedisReadCacheService($factory, ['connection' => 'cache']);

        $this->assertEquals($rows, $service->get($key));
        $this->assertEquals($rows, $service->get($key));
        $this->assertSame(1, $redis->getCalls);

        $nextRequest = new RedisReadCacheService($factory, ['connection' => 'cache']);
        $this->assertEquals($rows, $nextRequest->get($key));
        $this->assertSame(2, $redis->getCalls);
    }

    public function test_write_invalidation_clears_request_local_results(): void
    {
        $redis = new InMemoryRedisConnection;
        $key = 'redis_read_cache:test';
        $redis->values[$key] = serialize([(object) ['id' => 1]]);

        $factory = $this->createMock(RedisFactory::class);
        $factory->expects($this->any())
            ->method('connection')
            ->with('cache')
            ->willReturn($redis);

        $service = new RedisReadCacheService($factory, ['connection' => 'cache']);
        $service->get($key);
        $service->invalidateAll();
        $service->get($key);

        $this->assertSame(2, $redis->getCalls);
        $this->assertArrayNotHasKey($key, $redis->values);
    }

    public function test_large_results_are_not_retained_in_request_local_cache(): void
    {
        $redis = new InMemoryRedisConnection;
        $key = 'redis_read_cache:large';
        $redis->values[$key] = serialize(str_repeat('x', 262145));

        $factory = $this->createMock(RedisFactory::class);
        $factory->expects($this->any())
            ->method('connection')
            ->with('cache')
            ->willReturn($redis);

        $service = new RedisReadCacheService($factory, ['connection' => 'cache']);

        $this->assertSame(str_repeat('x', 262145), $service->get($key));
        $this->assertSame(str_repeat('x', 262145), $service->get($key));
        $this->assertSame(2, $redis->getCalls);
    }

    public function test_warmed_entries_are_counted_as_redis_reads_after_warm(): void
    {
        $redis = new InMemoryRedisConnection;
        $factory = $this->createMock(RedisFactory::class);
        $factory->expects($this->any())
            ->method('connection')
            ->with('cache')
            ->willReturn($redis);
        $service = new RedisReadCacheService($factory, [
            'enabled' => true,
            'connection' => 'cache',
            'prefix' => 'redis_read_cache:',
            'ttl' => 300,
            'dashboard' => [
                'enabled' => true,
                'metrics_prefix' => 'metrics_test:',
            ],
        ]);
        $rows = [(object) ['id' => 1]];

        $this->assertTrue($service->cacheSelectResult('sqlsrv', 'app', 'SELECT * FROM units', [], $rows));
        $this->assertTrue($service->flushMetrics());
        $key = $service->buildKey('sqlsrv', 'app', 'SELECT * FROM units', []);
        $reader = new RedisReadCacheService($factory, [
            'enabled' => true,
            'connection' => 'cache',
            'dashboard' => [
                'enabled' => true,
                'metrics_prefix' => 'metrics_test:',
            ],
        ]);
        $this->assertEquals($rows, $reader->get($key));
        $this->assertTrue($reader->lastReadWasWarm());
        $this->assertTrue($reader->flushMetrics());

        $metrics = $service->metrics();
        $this->assertSame('1', (string) $metrics['warmed_rows']);
        $this->assertSame('1', (string) $metrics['warmed_tables']);
        $this->assertSame('1', (string) $metrics['reads_redis']);
        $this->assertSame('1', (string) $metrics['reads_after_warm']);
    }

    public function test_warmed_entries_are_counted_when_reused_from_local_memory(): void
    {
        $redis = new InMemoryRedisConnection;
        $factory = $this->createMock(RedisFactory::class);
        $factory->expects($this->any())
            ->method('connection')
            ->with('cache')
            ->willReturn($redis);
        $service = new RedisReadCacheService($factory, [
            'enabled' => true,
            'connection' => 'cache',
            'dashboard' => [
                'enabled' => true,
                'metrics_prefix' => 'metrics_local_test:',
            ],
        ]);
        $rows = [(object) ['id' => 1]];

        $this->assertTrue($service->cacheSelectResult('sqlsrv', 'app', 'SELECT * FROM units', [], $rows));
        $key = $service->buildKey('sqlsrv', 'app', 'SELECT * FROM units', []);
        $this->assertEquals($rows, $service->get($key));
        $this->assertTrue($service->lastReadWasWarm());
        $this->assertTrue($service->flushMetrics());

        $metrics = $service->metrics();
        $this->assertSame('1', (string) $metrics['reads_local']);
        $this->assertSame('1', (string) $metrics['reads_after_warm']);
    }

    public function test_profiler_aggregates_queries_without_storing_bindings(): void
    {
        $redis = new InMemoryRedisConnection;
        $factory = $this->createMock(RedisFactory::class);
        $factory->expects($this->any())
            ->method('connection')
            ->with('cache')
            ->willReturn($redis);

        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabaseName')->willReturn('app_database');
        $profiler = new RedisReadCacheProfiler($factory, [
            'enabled' => true,
            'connection' => 'cache',
            'prefix' => 'profile_test:',
            'ttl' => 3600,
            'slow_page_ms' => 1000,
        ]);
        $sql = "select * from users where email = ? and id = ?";

        $profiler->record(new QueryExecuted($sql, ['private@example.com', 42], 25.5, $connection), 'users.index');
        $profiler->record(new QueryExecuted($sql, ['another@example.com', 43], 14.5, $connection), 'users.index');
        $profiler->record(
            new QueryExecuted("select * from users where email = 'literal-secret' and id = 0x2a", [], 1.0, $connection),
            'users.index'
        );

        $request = new class
        {
            public function route(): object
            {
                return new class
                {
                    public function getName(): string
                    {
                        return 'users.index';
                    }

                    public function uri(): string
                    {
                        return 'users';
                    }
                };
            }

            public function method(): string
            {
                return 'GET';
            }

            public function server(string $key): float
            {
                return microtime(true) - 0.05;
            }
        };

        $this->assertTrue($profiler->flush($request));
        $profiles = $profiler->profiles();
        $queryProfiles = array_values(array_filter($profiles, fn (array $profile): bool => $profile['type'] === 'query'));

        $this->assertCount(1, $queryProfiles);
        $this->assertSame('3', (string) $queryProfiles[0]['executions']);
        $this->assertSame('1', (string) $queryProfiles[0]['requests']);
        $this->assertEquals(41, (float) $queryProfiles[0]['total_ms']);
        $this->assertStringNotContainsString('private@example.com', $queryProfiles[0]['query']);
        $this->assertStringNotContainsString('literal-secret', $queryProfiles[0]['query']);
        $this->assertStringContainsString('users.index', $queryProfiles[0]['route']);
    }
}

class InMemoryRedisConnection implements RedisConnection
{
    public array $values = [];

    public int $getCalls = 0;

    public array $hashes = [];

    public array $sets = [];

    public function subscribe($channels, \Closure $callback): void {}

    public function psubscribe($channels, \Closure $callback): void {}

    public function pipeline(?callable $callback = null): mixed
    {
        $pipeline = new InMemoryRedisPipeline($this);

        if ($callback === null) {
            return $pipeline;
        }

        $callback($pipeline);

        return $pipeline->exec();
    }

    public function command($method, array $parameters = []): mixed
    {
        $method = strtolower($method);

        if ($method === 'get') {
            $this->getCalls++;

            return $this->values[$parameters[0]] ?? false;
        }

        if ($method === 'setex') {
            $this->values[$parameters[0]] = $parameters[2];

            return true;
        }

        if ($method === 'keys') {
            $prefix = rtrim($parameters[0], '*');

            return array_values(array_filter(
                array_keys($this->values),
                fn (string $key): bool => str_starts_with($key, $prefix)
            ));
        }

        if ($method === 'del') {
            foreach ((array) $parameters[0] as $key) {
                unset($this->values[$key]);
            }

            return true;
        }

        if ($method === 'ping') {
            return 'PONG';
        }

        if ($method === 'sadd') {
            $this->sets[$parameters[0]][$parameters[1]] = true;

            return 1;
        }

        if ($method === 'smembers') {
            return array_keys($this->sets[$parameters[0]] ?? []);
        }

        if ($method === 'srem') {
            unset($this->sets[$parameters[0]][$parameters[1]]);

            return 1;
        }

        if ($method === 'expire') {
            return true;
        }

        if ($method === 'hincrby') {
            $this->hashes[$parameters[0]][$parameters[1]] =
                (int) ($this->hashes[$parameters[0]][$parameters[1]] ?? 0) + (int) $parameters[2];

            return $this->hashes[$parameters[0]][$parameters[1]];
        }

        if ($method === 'hincrbyfloat') {
            $this->hashes[$parameters[0]][$parameters[1]] =
                (float) ($this->hashes[$parameters[0]][$parameters[1]] ?? 0) + (float) $parameters[2];

            return $this->hashes[$parameters[0]][$parameters[1]];
        }

        if ($method === 'hset') {
            $this->hashes[$parameters[0]][$parameters[1]] = (string) $parameters[2];

            return 1;
        }

        if ($method === 'hgetall') {
            return $this->hashes[$parameters[0]] ?? [];
        }

        return false;
    }

    public function __call(string $method, array $parameters): mixed
    {
        return $this->command($method, $parameters);
    }
}

class InMemoryRedisPipeline
{
    protected array $commands = [];

    public function __construct(protected InMemoryRedisConnection $redis) {}

    public function __call(string $method, array $parameters): self
    {
        $this->commands[] = [$method, $parameters];

        return $this;
    }

    public function exec(): array
    {
        return array_map(
            fn (array $command): mixed => $this->redis->command($command[0], $command[1]),
            $this->commands
        );
    }
}
