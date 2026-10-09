<?php

namespace KybDev\RedisReadCache\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use KybDev\RedisReadCache\Services\RedisReadCacheService;

class RedisHotRunnerCommand extends Command
{
    protected $signature = 'redis:hot-runner
                            {--tables=* : Specific tables to warm. Defaults to all discovered tables.}
                            {--limit=500 : Maximum number of rows to warm per table.}
                            {--connection= : Database connection to inspect. Defaults to the app default connection.}
                            {--force : Flush the Redis read cache before warming.}
                            {--all : Alias for warming all discovered tables.}';

    protected $description = 'Warm the Redis read-through cache with a fresh snapshot of database data.';

    public function handle(): int
    {
        $cacheService = app(RedisReadCacheService::class);

        if (! $cacheService->enabled()) {
            $this->error('REDIS_READ_CACHE_ENABLED must be true before running the hot runner.');

            return self::FAILURE;
        }

        if (! $cacheService->redisAvailable()) {
            $this->error('Redis is unavailable; no tables were warmed.');

            return self::FAILURE;
        }

        $connection = $this->option('connection') ?: config('database.default');
        $tables = $this->option('tables');
        $limit = max(1, (int) $this->option('limit'));

        if ($this->option('force')) {
            $cacheService->invalidateAll();
            $this->info('Cleared existing Redis read-cache entries.');
        }

        $tableNames = $this->resolveTableNames($connection, $tables);

        if (empty($tableNames)) {
            $this->warn('No tables were found to warm for connection ['.$connection.'].');

            return self::SUCCESS;
        }

        $this->info('Warming Redis read cache for ['.$connection.'] using '.count($tableNames).' table(s).');

        $warmed = 0;
        $failed = false;

        foreach ($tableNames as $table) {
            try {
                $this->line(' - warming '.$table.' (limit '.$limit.')');

                $database = DB::connection($connection);
                $query = $database->table($table)->limit($limit);
                $sql = $query->toSql();
                $bindings = $query->getBindings();
                $rows = $database->select($sql, $bindings);

                if (! $cacheService->cacheSelectResult(
                    $database->getName(),
                    $database->getDatabaseName(),
                    $sql,
                    $bindings,
                    $rows
                )) {
                    $this->error('Failed to cache table ['.$table.']. Check Redis connectivity and cache configuration.');
                    $failed = true;

                    continue;
                }

                $warmed += count($rows);
            } catch (\Throwable $exception) {
                $this->warn('Skipped table ['.$table.']: '.$exception->getMessage());
                $failed = true;
            }
        }

        $this->info('Redis hot runner completed. Warmed '.$warmed.' rows across '.count($tableNames).' table(s).');

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    protected function resolveTableNames(string $connection, array $tables): array
    {
        if (! empty($tables)) {
            return array_values(array_filter(array_map('trim', $tables), fn ($table) => $table !== ''));
        }

        return array_values(array_filter(
            Schema::connection($connection)->getTableListing(),
            fn ($table) => ! in_array($table, ['migrations', 'failed_jobs', 'job_batches', 'sessions'], true)
        ));
    }
}
