<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>SEO Report — {{ $domain }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #0b1220; margin: 28px; }
        h1 { font-size: 22px; margin: 0 0 4px; }
        h2 { font-size: 14px; margin: 22px 0 8px; border-bottom: 1px solid #d5dce6; padding-bottom: 4px; }
        .muted { color: #5b667a; font-size: 11px; }
        .score { font-size: 28px; font-weight: bold; color: #0b7f73; }
        table { width: 100%; border-collapse: collapse; margin-top: 6px; }
        th, td { border: 1px solid #d5dce6; padding: 6px 8px; text-align: left; vertical-align: top; }
        th { background: #f3f5f8; font-size: 11px; text-transform: uppercase; letter-spacing: .04em; }
        .pill { display: inline-block; padding: 1px 6px; border-radius: 3px; font-size: 10px; font-weight: bold; text-transform: uppercase; }
        .critical { background: #ffe4e6; color: #9f1239; }
        .warning { background: #fef3c7; color: #92400e; }
        .info { background: #e0f2fe; color: #075985; }
        .high { background: #ffe4e6; color: #9f1239; }
        .medium { background: #fef3c7; color: #92400e; }
        .low { background: #e0f2fe; color: #075985; }
        ul { margin: 6px 0 0; padding-left: 18px; }
    </style>
</head>
<body>
    <h1>{{ $summary['period_label'] ?? ucfirst($period) }} SEO report</h1>
    <div class="muted">{{ $domain }} · {{ $period_start }} → {{ $period_end }} · Generated {{ $generated_at }}</div>

    <h2>Overview</h2>
    <table>
        <tr>
            <th>Health</th>
            <th>Open issues</th>
            <th>Critical</th>
            <th>Pages</th>
            <th>Keywords</th>
            <th>Avg position</th>
        </tr>
        <tr>
            <td><span class="score">{{ $summary['health_score'] ?? '—' }}%</span></td>
            <td>{{ $summary['open_issues'] ?? '—' }}</td>
            <td>{{ $summary['critical_issues'] ?? '—' }}</td>
            <td>{{ $summary['pages_crawled'] ?? '—' }}</td>
            <td>{{ $summary['keywords_tracked'] ?? '—' }}</td>
            <td>{{ $summary['avg_position'] ?? '—' }}</td>
        </tr>
    </table>

    @if (!empty($summary['highlights']))
        <h2>Highlights</h2>
        <ul>
            @foreach ($summary['highlights'] as $line)
                <li>{{ $line }}</li>
            @endforeach
        </ul>
    @endif

    <h2>Open issues ({{ count($issues) }})</h2>
    @if (count($issues) === 0)
        <p class="muted">No open issues.</p>
    @else
        <table>
            <tr><th>Severity</th><th>Issue</th><th>Suggestion</th></tr>
            @foreach ($issues as $issue)
                <tr>
                    <td><span class="pill {{ $issue['severity'] }}">{{ $issue['severity'] }}</span></td>
                    <td>{{ $issue['message'] }}</td>
                    <td>{{ $issue['suggestion'] ?? '—' }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    <h2>Keywords ({{ count($keywords) }})</h2>
    @if (count($keywords) === 0)
        <p class="muted">No keywords tracked.</p>
    @else
        <table>
            <tr><th>Keyword</th><th>Group</th><th>Position</th></tr>
            @foreach ($keywords as $kw)
                <tr>
                    <td>{{ $kw['keyword'] }}</td>
                    <td>{{ $kw['group'] ?? '—' }}</td>
                    <td>{{ $kw['position'] ?? '—' }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    <h2>Open to-dos ({{ count($tasks) }})</h2>
    @if (count($tasks) === 0)
        <p class="muted">No open to-dos.</p>
    @else
        <table>
            <tr><th>Priority</th><th>Task</th><th>Status</th></tr>
            @foreach ($tasks as $task)
                <tr>
                    <td><span class="pill {{ $task['priority'] }}">{{ $task['priority'] }}</span></td>
                    <td>{{ $task['title'] }}</td>
                    <td>{{ $task['status'] }}</td>
                </tr>
            @endforeach
        </table>
    @endif
</body>
</html>
