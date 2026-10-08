<?php

namespace KybDev\RedisReadCache\Database;

use Illuminate\Database\SQLiteConnection;
use KybDev\RedisReadCache\Database\Concerns\InteractsWithRedisReadCache;

class RedisReadThroughSqliteConnection extends SQLiteConnection
{
    use InteractsWithRedisReadCache;
}
