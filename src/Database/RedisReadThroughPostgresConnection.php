<?php

namespace KybDev\RedisReadCache\Database;

use Illuminate\Database\PostgresConnection;
use KybDev\RedisReadCache\Database\Concerns\InteractsWithRedisReadCache;

class RedisReadThroughPostgresConnection extends PostgresConnection
{
    use InteractsWithRedisReadCache;
}
