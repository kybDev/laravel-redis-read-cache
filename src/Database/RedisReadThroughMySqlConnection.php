<?php

namespace Paimis\RedisReadCache\Database;

use Illuminate\Database\MySqlConnection;
use Paimis\RedisReadCache\Database\Concerns\InteractsWithRedisReadCache;

class RedisReadThroughMySqlConnection extends MySqlConnection
{
    use InteractsWithRedisReadCache;
}
