<?php

namespace KybDev\RedisReadCache\Database;

use Illuminate\Database\SqlServerConnection;
use KybDev\RedisReadCache\Database\Concerns\InteractsWithRedisReadCache;

class RedisReadThroughSqlServerConnection extends SqlServerConnection
{
    use InteractsWithRedisReadCache;
}
