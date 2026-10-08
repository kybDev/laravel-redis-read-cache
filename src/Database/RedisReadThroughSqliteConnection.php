<?php

namespace Paimis\RedisReadCache\Database;

use Illuminate\Database\SQLiteConnection;
use Paimis\RedisReadCache\Database\Concerns\InteractsWithRedisReadCache;

class RedisReadThroughSqliteConnection extends SQLiteConnection
{
    use InteractsWithRedisReadCache;
}
