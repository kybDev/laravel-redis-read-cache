<?php

namespace KybDev\RedisReadCache\Console\Commands;

use Illuminate\Console\Command;
use KybDev\RedisReadCache\Services\RedisReadCacheProfiler;

class RedisReadCacheProfileCommand extends Command
{
    protected $signature = 'redis:profile
                            {--limit=20 : Maximum recommendations to show per section}';

    protected $description = 'Report slow pages and repeated database reads worth considering for caching.';

    public function handle(): int
    {
        $profiler = app(RedisReadCacheProfiler::class);

        if (! $profiler->enabled()) {
            $this->error('Enable REDIS_READ_CACHE_PROFILING_ENABLED before viewing profiles.');

            return self::FAILURE;
        }

        if (! $profiler->redisAvailable()) {
            $this->error('Redis is unavailable; profiler recommendations could not be loaded.');

            return self::FAILURE;
        }

        $profiles = $profiler->profiles();

        if ($profiles === null) {
            $this->error('Failed to read profiler data from Redis. See the application log for details.');

            return self::FAILURE;
        }

        $queryRecommendations = [];
        $pageRecommendations = [];
        $config = config('redis.read_cache.profiling', []);

        foreach ($profiles as $profile) {
            if (($profile['type'] ?? '') === 'page') {
                $requests = (int) ($profile['requests'] ?? 0);
                $totalMs = (float) ($profile['total_ms'] ?? 0);
                $averageMs = $requests > 0 ? $totalMs / $requests : 0;

                if (
                    $requests >= max(1, (int) ($config['min_requests'] ?? 3))
                    && $averageMs >= (float) ($config['slow_page_ms'] ?? 1000)
                ) {
                    $pageRecommendations[] = [
                        'route' => $profile['route'] ?? '(unknown)',
                        'requests' => $requests,
                        'average_ms' => round($averageMs, 2),
                        'slow_requests' => (int) ($profile['slow_requests'] ?? 0),
                    ];
                }

                continue;
            }

            $executions = (int) ($profile['executions'] ?? 0);
            $requests = (int) ($profile['requests'] ?? 0);
            $totalMs = (float) ($profile['total_ms'] ?? 0);
            $averageMs = $executions > 0 ? $totalMs / $executions : 0;
            $repeated = $executions >= max(1, (int) ($config['min_executions'] ?? 3));
            $expensive = $totalMs >= (float) ($config['min_total_query_ms'] ?? 100)
                || $averageMs >= (float) ($config['slow_query_ms'] ?? 20);

            if ($repeated && $expensive) {
                $queryRecommendations[] = [
                    'route' => $profile['route'] ?? '(unknown)',
                    'connection' => $profile['connection'] ?? '(unknown)',
                    'executions' => $executions,
                    'requests' => $requests,
                    'total_ms' => round($totalMs, 2),
                    'average_ms' => round($averageMs, 2),
                    'query' => $profile['query'] ?? '(unavailable)',
                ];
            }
        }

        $limit = max(1, (int) $this->option('limit'));
        usort($pageRecommendations, fn (array $a, array $b): int => $b['average_ms'] <=> $a['average_ms']);
        usort($queryRecommendations, fn (array $a, array $b): int => $b['total_ms'] <=> $a['total_ms']);

        $this->info('Profiler recommendations only; caching policy was not changed.');
        $this->line('Profiles retained: '.count($profiles));
        $this->newLine();
        $this->info('Slow pages');

        if ($pageRecommendations === []) {
            $this->line('No page met the configured request and duration thresholds.');
        } else {
            $this->table(
                ['Route', 'Requests', 'Average ms', 'Slow requests'],
                array_map(
                    fn (array $row): array => [$row['route'], $row['requests'], $row['average_ms'], $row['slow_requests']],
                    array_slice($pageRecommendations, 0, $limit)
                )
            );
        }

        $this->newLine();
        $this->info('Repeated / expensive query candidates');

        if ($queryRecommendations === []) {
            $this->line('No query met the configured repetition and cost thresholds.');
        } else {
            $this->table(
                ['Route', 'Connection', 'Executions', 'Requests', 'Total ms', 'Average ms', 'Normalized SQL'],
                array_map(
                    fn (array $row): array => [
                        $row['route'],
                        $row['connection'],
                        $row['executions'],
                        $row['requests'],
                        $row['total_ms'],
                        $row['average_ms'],
                        $row['query'],
                    ],
                    array_slice($queryRecommendations, 0, $limit)
                )
            );
        }

        return self::SUCCESS;
    }
}
