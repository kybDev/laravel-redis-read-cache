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
    'profiling' => [
        'enabled' => env('REDIS_READ_CACHE_PROFILING_ENABLED', false),
        'connection' => env('REDIS_READ_CACHE_PROFILING_CONNECTION', env('REDIS_READ_CACHE_CONNECTION', 'cache')),
        'prefix' => env('REDIS_READ_CACHE_PROFILING_PREFIX', 'redis_read_cache_profile:'),
        'ttl' => (int) env('REDIS_READ_CACHE_PROFILING_TTL', 604800),
        'sample_rate' => (float) env('REDIS_READ_CACHE_PROFILING_SAMPLE_RATE', 1),
        'max_records' => (int) env('REDIS_READ_CACHE_PROFILING_MAX_RECORDS', 1000),
        'min_executions' => (int) env('REDIS_READ_CACHE_PROFILING_MIN_EXECUTIONS', 3),
        'min_total_query_ms' => (float) env('REDIS_READ_CACHE_PROFILING_MIN_TOTAL_QUERY_MS', 100),
        'slow_query_ms' => (float) env('REDIS_READ_CACHE_PROFILING_SLOW_QUERY_MS', 20),
        'min_requests' => (int) env('REDIS_READ_CACHE_PROFILING_MIN_REQUESTS', 3),
        'slow_page_ms' => (float) env('REDIS_READ_CACHE_PROFILING_SLOW_PAGE_MS', 1000),
    ],
    'dashboard' => [
        'enabled' => env('REDIS_READ_CACHE_DASHBOARD_ENABLED', false),
        'path' => env('REDIS_READ_CACHE_DASHBOARD_PATH', 'redis-read-cache'),
        'middleware' => ['web', 'auth'],
        'metrics_prefix' => env('REDIS_READ_CACHE_METRICS_PREFIX', 'redis_read_cache:dashboard:'),
        'metrics_ttl' => (int) env('REDIS_READ_CACHE_METRICS_TTL', 2592000),
    ],
];
