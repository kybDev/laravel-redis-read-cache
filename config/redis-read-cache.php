<?php

return [
    'enabled' => env('REDIS_READ_CACHE_ENABLED', false),
    'connection' => env('REDIS_READ_CACHE_CONNECTION', 'cache'),
    'ttl' => (int) env('REDIS_READ_CACHE_TTL', 300),
    'prefix' => env('REDIS_READ_CACHE_PREFIX', 'redis_read_cache:'),
    'drivers' => array_values(array_filter(array_map('trim', explode(',', env('REDIS_READ_CACHE_DRIVERS', 'sqlsrv,mysql,pgsql,sqlite'))))),
    'controller_scope' => [
        'enabled' => env('REDIS_READ_CACHE_CONTROLLER_SCOPE', false),
        'paths' => array_values(array_filter(array_map(
            'trim',
            explode(',', env('REDIS_READ_CACHE_CONTROLLER_PATHS', 'app/Http/Controllers'))
        ))),
        'include_tables' => array_values(array_filter(array_map(
            'trim',
            explode(',', env('REDIS_READ_CACHE_CONTROLLER_INCLUDE_TABLES', ''))
        ))),
        'exclude_classes' => [
            \Illuminate\Database\Eloquent\Builder::class,
            \Illuminate\Database\Eloquent\Model::class,
        ],
    ],
];
