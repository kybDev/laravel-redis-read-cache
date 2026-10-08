<?php

namespace Paimis\RedisReadCache\Database;

use Illuminate\Database\PostgresConnection;
use Paimis\RedisReadCache\Database\Concerns\InteractsWithRedisReadCache;

class RedisReadThroughPostgresConnection extends PostgresConnection
{
    use InteractsWithRedisReadCache;
}
