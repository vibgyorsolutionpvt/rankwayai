<?php

namespace App\Services\Seo;

use App\Models\SeoIssue;
use App\Models\SeoKeyword;
use App\Models\SeoReport;
use App\Models\SeoTask;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SeoReportExporter
{
    /**
     * @return array{
     *   domain:string,
     *   period:string,
     *   period_start:?string,
     *   period_end:?string,
     *   generated_at:string,
     *   workspace:string,
     *   summary:array<string,mixed>,
     *   issues:list<array{severity:string,code:string,message:string,suggestion:?string}>,
     *   keywords:list<array{keyword:string,position:mixed,group:?string}>,
     *   tasks:list<array{title:string,priority:string,status:string}>
     * }
     */
    public function payload(SeoReport $report): array
    {
        $report->loadMissing(['site.workspace']);
        $summary = is_array($report->summary) ? $report->summary : [];
        $site = $report->site;
        $workspaceId = $report->workspace_id;

        $issues = $site
            ? SeoIssue::query()
                ->where('seo_site_id', $site->id)
                ->where('status', 'open')
                ->orderByRaw("CASE severity WHEN 'critical' THEN 1 WHEN 'warning' THEN 2 ELSE 3 END")
                ->limit(40)
                ->get(['severity', 'code', 'message', 'suggestion'])
                ->map(fn (SeoIssue $i) => [
                    'severity' => $i->severity,
                    'code' => $i->code,
                    'message' => $i->message,
                    'suggestion' => $i->suggestion,
                ])
                ->all()
            : [];

        $keywords = SeoKeyword::query()
            ->where('workspace_id', $workspaceId)
            ->orderBy('position')
            ->limit(40)
            ->get(['keyword', 'position', 'group_name'])
            ->map(fn (SeoKeyword $k) => [
                'keyword' => $k->keyword,
                'position' => $k->position,
                'group' => $k->group_name,
            ])
            ->all();

        $tasks = SeoTask::query()
            ->where('workspace_id', $workspaceId)
            ->when($site, fn ($q) => $q->where('seo_site_id', $site->id))
            ->where('status', 'open')
            ->latest()
            ->limit(30)
            ->get(['title', 'priority', 'status'])
            ->map(fn (SeoTask $t) => [
                'title' => $t->title,
                'priority' => $t->priority,
                'status' => $t->status,
            ])
            ->all();

        return [
            'domain' => (string) ($summary['domain'] ?? $site?->domain ?? 'site'),
            'period' => (string) $report->period,
            'period_start' => $report->period_start?->toDateString(),
            'period_end' => $report->period_end?->toDateString(),
            'generated_at' => $report->created_at?->toDayDateTimeString() ?? now()->toDayDateTimeString(),
            'workspace' => (string) ($site?->workspace?->name ?? 'Workspace'),
            'summary' => $summary,
            'issues' => $issues,
            'keywords' => $keywords,
            'tasks' => $tasks,
        ];
    }

    public function filename(SeoReport $report, string $ext): string
    {
        $domain = preg_replace('/[^a-z0-9.-]+/i', '-', (string) ($report->summary['domain'] ?? 'seo')) ?: 'seo';
        $date = ($report->period_end ?? now())->format('Y-m-d');

        return "seo-{$report->period}-{$domain}-{$date}.{$ext}";
    }

    public function downloadPdf(SeoReport $report): Response
    {
        $data = $this->payload($report);
        $pdf = Pdf::loadView('seo.report-pdf', $data)->setPaper('a4');

        return $pdf->download($this->filename($report, 'pdf'));
    }

    public function downloadExcel(SeoReport $report): StreamedResponse
    {
        $data = $this->payload($report);
        $spreadsheet = $this->buildSpreadsheet($data);
        $filename = $this->filename($report, 'xlsx');

        return response()->streamDownload(function () use ($spreadsheet) {
            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
            $writer->save('php://output');
            $spreadsheet->disconnectWorksheets();
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function buildSpreadsheet(array $data): \PhpOffice\PhpSpreadsheet\Spreadsheet
    {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet;
        $summarySheet = $spreadsheet->getActiveSheet();
        $summarySheet->setTitle('Summary');

        $summary = $data['summary'] ?? [];
        $summaryRows = [
            ['Field', 'Value'],
            ['Domain', $data['domain']],
            ['Period', $data['period']],
            ['Period start', (string) ($data['period_start'] ?? '')],
            ['Period end', (string) ($data['period_end'] ?? '')],
            ['Generated', $data['generated_at']],
            ['Health score', (string) ($summary['health_score'] ?? '—')],
            ['Open issues', (string) ($summary['open_issues'] ?? '—')],
            ['Critical issues', (string) ($summary['critical_issues'] ?? '—')],
            ['Pages crawled', (string) ($summary['pages_crawled'] ?? '—')],
            ['Keywords tracked', (string) ($summary['keywords_tracked'] ?? '—')],
            ['Avg position', (string) ($summary['avg_position'] ?? '—')],
        ];
        foreach (($summary['highlights'] ?? []) as $i => $line) {
            $summaryRows[] = ['Highlight '.($i + 1), (string) $line];
        }
        $summarySheet->fromArray($summaryRows, null, 'A1');
        $summarySheet->getStyle('A1:B1')->getFont()->setBold(true);
        foreach (range('A', 'B') as $col) {
            $summarySheet->getColumnDimension($col)->setAutoSize(true);
        }

        $issuesSheet = $spreadsheet->createSheet();
        $issuesSheet->setTitle('Issues');
        $issueRows = [['Severity', 'Code', 'Message', 'Suggestion']];
        foreach ($data['issues'] as $issue) {
            $issueRows[] = [
                (string) $issue['severity'],
                (string) $issue['code'],
                (string) $issue['message'],
                (string) ($issue['suggestion'] ?? ''),
            ];
        }
        $issuesSheet->fromArray($issueRows, null, 'A1');
        $issuesSheet->getStyle('A1:D1')->getFont()->setBold(true);
        foreach (range('A', 'D') as $col) {
            $issuesSheet->getColumnDimension($col)->setAutoSize(true);
        }

        $kwSheet = $spreadsheet->createSheet();
        $kwSheet->setTitle('Keywords');
        $kwRows = [['Keyword', 'Group', 'Position']];
        foreach ($data['keywords'] as $kw) {
            $kwRows[] = [
                (string) $kw['keyword'],
                (string) ($kw['group'] ?? ''),
                $kw['position'] === null ? '—' : (string) $kw['position'],
            ];
        }
        $kwSheet->fromArray($kwRows, null, 'A1');
        $kwSheet->getStyle('A1:C1')->getFont()->setBold(true);
        foreach (range('A', 'C') as $col) {
            $kwSheet->getColumnDimension($col)->setAutoSize(true);
        }

        $taskSheet = $spreadsheet->createSheet();
        $taskSheet->setTitle('To-dos');
        $taskRows = [['Priority', 'Title', 'Status']];
        foreach ($data['tasks'] as $task) {
            $taskRows[] = [
                (string) $task['priority'],
                (string) $task['title'],
                (string) $task['status'],
            ];
        }
        $taskSheet->fromArray($taskRows, null, 'A1');
        $taskSheet->getStyle('A1:C1')->getFont()->setBold(true);
        foreach (range('A', 'C') as $col) {
            $taskSheet->getColumnDimension($col)->setAutoSize(true);
        }

        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }
}
