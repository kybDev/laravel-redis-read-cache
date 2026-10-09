<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Redis Read Cache Dashboard</title>
    <style>
        :root { color-scheme: light; font-family: system-ui, sans-serif; color: #172033; background: #f3f6fb; }
        body { margin: 0; padding: 2rem; }
        main { max-width: 1100px; margin: 0 auto; }
        h1 { margin-bottom: .35rem; }
        .muted { color: #64748b; margin-top: 0; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 1rem; margin: 1.5rem 0; }
        .card { background: #fff; border: 1px solid #dbe3ef; border-radius: .75rem; padding: 1.25rem; box-shadow: 0 2px 8px #1720330a; }
        .label { font-size: .9rem; color: #64748b; }
        .value { display: block; font-size: 2rem; font-weight: 700; margin-top: .4rem; }
        .status { display: inline-block; border-radius: 2rem; padding: .25rem .65rem; font-size: .85rem; background: #e2e8f0; }
        .on { color: #166534; background: #dcfce7; }
        .off { color: #475569; }
        table { border-collapse: collapse; width: 100%; background: #fff; }
        th, td { padding: .8rem; text-align: left; border-bottom: 1px solid #e2e8f0; }
        th { color: #475569; font-size: .85rem; }
        @media (max-width: 600px) { body { padding: 1rem; } }
    </style>
</head>
<body>
<main>
    <h1>Redis Read Cache</h1>
    <p class="muted">Runtime counters are stored in Redis. No database migration is required.</p>

    <section class="grid" aria-label="Feature status">
        <article class="card"><span class="label">Redis connection</span><br><span class="status {{ $redisAvailable ? 'on' : 'off' }}">{{ $redisAvailable ? 'Available' : 'Unavailable' }}</span></article>
        <article class="card"><span class="label">Read-through cache</span><br><span class="status {{ $cacheEnabled ? 'on' : 'off' }}">{{ $cacheEnabled ? 'Enabled' : 'Disabled' }}</span></article>
        <article class="card"><span class="label">Controller scope</span><br><span class="status {{ $scopeEnabled ? 'on' : 'off' }}">{{ $scopeEnabled ? 'Enabled' : 'Disabled' }}</span></article>
        <article class="card"><span class="label">Query profiling</span><br><span class="status {{ $profilingEnabled ? 'on' : 'off' }}">{{ $profilingEnabled ? 'Enabled' : 'Disabled' }}</span></article>
        <article class="card"><span class="label">Profile records retained</span><span class="value">{{ number_format($profileCount) }}</span></article>
    </section>

    <h2>Read counts</h2>
    <section class="grid" aria-label="Read metrics">
        <article class="card"><span class="label">Reads served from Redis</span><span class="value">{{ number_format((int) ($metrics['reads_redis'] ?? 0)) }}</span></article>
        <article class="card"><span class="label">Reads served from SQL</span><span class="value">{{ number_format((int) ($metrics['reads_sql'] ?? 0)) }}</span></article>
        <article class="card"><span class="label">Reads after warm-up</span><span class="value">{{ number_format((int) ($metrics['reads_after_warm'] ?? 0)) }}</span></article>
        <article class="card"><span class="label">Repeated reads served locally</span><span class="value">{{ number_format((int) ($metrics['reads_local'] ?? 0)) }}</span></article>
    </section>

    <h2>Warm runner</h2>
    <table>
        <thead><tr><th>Metric</th><th>Total</th></tr></thead>
        <tbody>
        <tr><td>Warm runs</td><td>{{ number_format((int) ($metrics['warm_runs'] ?? 0)) }}</td></tr>
        <tr><td>Tables warmed</td><td>{{ number_format((int) ($metrics['warmed_tables'] ?? 0)) }}</td></tr>
        <tr><td>Rows warmed</td><td>{{ number_format((int) ($metrics['warmed_rows'] ?? 0)) }}</td></tr>
        </tbody>
    </table>
    <p class="muted">Counters expire after {{ (int) ($dashboardConfig['metrics_ttl'] ?? 2592000) }} seconds. “After warm-up” includes reads from Redis or request-local memory for entries populated by the hot runner. SQL counts include reads bypassing the cache and cache misses.</p>
</main>
</body>
</html>
