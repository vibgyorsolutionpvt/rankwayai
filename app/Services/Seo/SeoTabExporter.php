<?php

namespace App\Services\Seo;

use App\Models\SeoIssue;
use App\Models\SeoKeyword;
use App\Models\SeoSite;
use App\Models\SeoTask;
use App\Models\Workspace;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SeoTabExporter
{
    public function download(Workspace $workspace, string $type, ?SeoSite $site = null): StreamedResponse
    {
        $type = strtolower($type);
        if (! in_array($type, ['issues', 'fix', 'keywords', 'tasks', 'todos'], true)) {
            abort(404, 'Unknown export. Use issues, keywords, or tasks.');
        }

        [$sheetTitle, $headers, $rows, $filename] = match ($type) {
            'issues', 'fix' => $this->issuesSheet($workspace, $site),
            'keywords' => $this->keywordsSheet($workspace),
            'tasks', 'todos' => $this->tasksSheet($workspace, $site),
        };

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle($sheetTitle);
        $sheet->fromArray(array_merge([$headers], $rows), null, 'A1');
        $sheet->getStyle('A1:'.chr(64 + max(1, count($headers))).'1')->getFont()->setBold(true);
        foreach (range('A', chr(64 + max(1, count($headers)))) as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        return response()->streamDownload(function () use ($spreadsheet) {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
            $spreadsheet->disconnectWorksheets();
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * @return array{0:string,1:list<string>,2:list<list<string|int|null>>,3:string}
     */
    private function issuesSheet(Workspace $workspace, ?SeoSite $site): array
    {
        $query = SeoIssue::query()
            ->where('workspace_id', $workspace->id)
            ->where('status', 'open')
            ->with('page:id,url')
            ->orderByRaw("CASE severity WHEN 'critical' THEN 1 WHEN 'warning' THEN 2 ELSE 3 END")
            ->limit(500);

        if ($site) {
            $query->where('seo_site_id', $site->id);
        }

        $rows = $query->get()->map(fn (SeoIssue $i) => [
            $i->severity,
            $i->code,
            $i->message,
            $i->suggestion,
            $i->page?->url,
            $i->status,
        ])->all();

        $domain = $site?->domain ?? 'workspace';

        return [
            'Issues',
            ['Severity', 'Code', 'Message', 'Suggestion', 'Page URL', 'Status'],
            $rows,
            'seo-issues-'.preg_replace('/[^a-z0-9.-]+/i', '-', $domain).'-'.now()->format('Y-m-d').'.xlsx',
        ];
    }

    /**
     * @return array{0:string,1:list<string>,2:list<list<string|int|null>>,3:string}
     */
    private function keywordsSheet(Workspace $workspace): array
    {
        $rows = SeoKeyword::query()
            ->where('workspace_id', $workspace->id)
            ->orderBy('keyword')
            ->limit(500)
            ->get()
            ->map(fn (SeoKeyword $k) => [
                $k->keyword,
                $k->group_name,
                $k->search_volume,
                $k->keyword_difficulty,
                $k->position,
                $k->position_change,
                $k->is_local ? 'yes' : 'no',
                $k->location,
                optional($k->last_checked_at)?->toDateTimeString(),
            ])
            ->all();

        return [
            'Keywords',
            ['Keyword', 'Group', 'Volume', 'KD', 'Rank', 'Change', 'Local', 'Location', 'Last checked'],
            $rows,
            'seo-keywords-'.$workspace->id.'-'.now()->format('Y-m-d').'.xlsx',
        ];
    }

    /**
     * @return array{0:string,1:list<string>,2:list<list<string|int|null>>,3:string}
     */
    private function tasksSheet(Workspace $workspace, ?SeoSite $site): array
    {
        $query = SeoTask::query()
            ->where('workspace_id', $workspace->id)
            ->where('status', 'open')
            ->latest()
            ->limit(500);

        if ($site) {
            $query->where('seo_site_id', $site->id);
        }

        $rows = $query->get()->map(fn (SeoTask $t) => [
            $t->priority,
            $t->title,
            $t->description,
            $t->status,
            $t->source,
            optional($t->due_on)?->toDateString(),
        ])->all();

        $domain = $site?->domain ?? 'workspace';

        return [
            'To-dos',
            ['Priority', 'Title', 'Description', 'Status', 'Source', 'Due'],
            $rows,
            'seo-todos-'.preg_replace('/[^a-z0-9.-]+/i', '-', $domain).'-'.now()->format('Y-m-d').'.xlsx',
        ];
    }
}
