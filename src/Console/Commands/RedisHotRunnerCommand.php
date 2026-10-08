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

        foreach ($tableNames as $table) {
            try {
                $this->line(' - warming '.$table.' (limit '.$limit.')');

                $rows = DB::connection($connection)
                    ->table($table)
                    ->limit($limit)
                    ->get();

                $warmed += $rows->count();
            } catch (\Throwable $exception) {
                $this->warn('Skipped table ['.$table.']: '.$exception->getMessage());
            }
        }

        $this->info('Redis hot runner completed. Warmed '.$warmed.' rows across '.count($tableNames).' table(s).');

        return self::SUCCESS;
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
