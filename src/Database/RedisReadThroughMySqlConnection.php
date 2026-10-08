<?php

namespace KybDev\RedisReadCache\Database;

use Illuminate\Database\MySqlConnection;
use KybDev\RedisReadCache\Database\Concerns\InteractsWithRedisReadCache;

class RedisReadThroughMySqlConnection extends MySqlConnection
{
    use InteractsWithRedisReadCache;
}
