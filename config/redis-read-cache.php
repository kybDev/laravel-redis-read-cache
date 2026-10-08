<?php

return [
    'enabled' => env('REDIS_READ_CACHE_ENABLED', false),
    'connection' => env('REDIS_READ_CACHE_CONNECTION', 'cache'),
    'ttl' => (int) env('REDIS_READ_CACHE_TTL', 300),
    'prefix' => env('REDIS_READ_CACHE_PREFIX', 'redis_read_cache:'),
    'drivers' => array_values(array_filter(array_map('trim', explode(',', env('REDIS_READ_CACHE_DRIVERS', 'sqlsrv,mysql,pgsql,sqlite'))))),
];
