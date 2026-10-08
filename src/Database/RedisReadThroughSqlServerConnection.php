<?php

namespace Paimis\RedisReadCache\Database;

use Illuminate\Database\SqlServerConnection;
use Paimis\RedisReadCache\Database\Concerns\InteractsWithRedisReadCache;

class RedisReadThroughSqlServerConnection extends SqlServerConnection
{
    use InteractsWithRedisReadCache;
}
