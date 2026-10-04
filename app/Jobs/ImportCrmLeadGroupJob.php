<?php

namespace App\Jobs;

use App\Models\CrmLead;
use App\Models\CrmLeadGroup;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Throwable;

class ImportCrmLeadGroupJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 80;

    public int $tries = 1;

    public function __construct(public int $groupId, public string $path) {}

    public function handle(): void
    {
        $group = CrmLeadGroup::query()->find($this->groupId);
        if (! $group) {
            Storage::disk('local')->delete($this->path);

            return;
        }

        $group->update(['status' => 'processing', 'error_message' => null]);
        $fullPath = Storage::disk('local')->path($this->path);
        $convertedPath = null;
        if (in_array(strtolower(pathinfo($this->path, PATHINFO_EXTENSION)), ['xls', 'xlsx'], true)) {
            try {
                $convertedPath = $this->spreadsheetToCsv($fullPath);
                $fullPath = Storage::disk('local')->path($convertedPath);
            } catch (Throwable $exception) {
                report($exception);
                $group->update([
                    'status' => $group->leads()->exists() ? 'completed' : 'failed',
                    'error_message' => $exception instanceof \RuntimeException
                        ? mb_substr($exception->getMessage(), 0, 250)
                        : 'The Excel file could not be read. Save it as .xlsx or .csv and try again.',
                ]);
                Storage::disk('local')->delete($this->path);

                return;
            }
        }

        $handle = @fopen($fullPath, 'rb');
        if ($handle === false) {
            $group->update([
                'status' => $group->leads()->exists() ? 'completed' : 'failed',
                'error_message' => 'The uploaded file could not be opened.',
            ]);
            Storage::disk('local')->delete(array_filter([$this->path, $convertedPath]));

            return;
        }

        try {
            $headers = fgetcsv($handle, 0, ',', '"', '');
            if (! is_array($headers)) {
                throw new \RuntimeException('The CSV file is empty.');
            }
            $headers = array_map(
                fn ($header) => ltrim(strtolower(trim((string) $header)), "\xEF\xBB\xBF"),
                $headers,
            );
            $headerIndexes = array_flip($headers);
            if (! isset($headerIndexes['name'], $headerIndexes['phone'])) {
                throw new \RuntimeException('The file must include name and phone columns in the first row.');
            }

            $totalRows = 0;
            while (($values = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                if ($values !== [null] && ! (count($values) === 1 && trim((string) $values[0]) === '')) {
                    $totalRows++;
                    if ($totalRows > 20000) {
                        throw new \RuntimeException('A group can contain up to 20,000 CSV rows. Split larger files into multiple groups.');
                    }
                }
            }
            rewind($handle);
            fgetcsv($handle, 0, ',', '"', '');

            $definitions = collect($group->workspace->crm_lead_custom_fields ?? [])->keyBy('key');
            $phoneKey = static fn (string $phone): string => preg_replace('/\D+/', '', $phone) ?: '';
            $phoneLookup = [];
            CrmLead::query()
                ->where('workspace_id', $group->workspace_id)
                ->whereNotNull('phone')
                ->select(['id', 'phone'])
                ->chunkById(1000, function ($leads) use (&$phoneLookup, $phoneKey) {
                    foreach ($leads as $lead) {
                        $key = $phoneKey((string) $lead->phone);
                        if ($key !== '') {
                            $phoneLookup[$key] = $lead->id;
                        }
                    }
                });

            $created = 0;
            $updated = 0;
            $skipped = 0;
            $processedRows = 0;
            $batch = [];
            while (($values = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                if ($values === [null] || (count($values) === 1 && trim((string) $values[0]) === '')) {
                    continue;
                }
                $processedRows++;
                $batch[] = $values;
                if (count($batch) >= 250) {
                    [$newCount, $updateCount, $skipCount] = $this->importBatch(
                        $batch,
                        $headerIndexes,
                        $definitions,
                        $phoneLookup,
                        $phoneKey,
                        $group,
                    );
                    $created += $newCount;
                    $updated += $updateCount;
                    $skipped += $skipCount;
                    $batch = [];
                    $group->update([
                        'total_rows' => $processedRows,
                        'created_count' => $created,
                        'updated_count' => $updated,
                        'skipped_count' => $skipped,
                    ]);
                }
            }
            if ($batch !== []) {
                [$newCount, $updateCount, $skipCount] = $this->importBatch(
                    $batch,
                    $headerIndexes,
                    $definitions,
                    $phoneLookup,
                    $phoneKey,
                    $group,
                );
                $created += $newCount;
                $updated += $updateCount;
                $skipped += $skipCount;
            }

            $group->update([
                'status' => 'completed',
                'total_rows' => $processedRows,
                'created_count' => $created,
                'updated_count' => $updated,
                'skipped_count' => $skipped,
            ]);
        } catch (Throwable $exception) {
            report($exception);
            $group->update([
                'status' => $group->leads()->exists() ? 'completed' : 'failed',
                'total_rows' => $group->total_rows,
                'error_message' => mb_substr($exception->getMessage(), 0, 250),
            ]);
        } finally {
            fclose($handle);
            Storage::disk('local')->delete(array_filter([$this->path, $convertedPath]));
        }
    }

    /**
     * Writes the first worksheet to a CSV next to the upload and returns its storage path.
     */
    private function spreadsheetToCsv(string $fullPath): string
    {
        $reader = IOFactory::createReaderForFile($fullPath);
        $reader->setReadDataOnly(true);
        $reader->setReadEmptyCells(false);
        $sheet = $reader->load($fullPath)->getSheet(0);

        if ($sheet->getHighestDataRow() > 20001) {
            throw new \RuntimeException('A group can contain up to 20,000 rows per file. Split larger files into multiple imports.');
        }

        $csvPath = preg_replace('/\.[a-z]+$/i', '', $this->path).'.converted.csv';
        $out = fopen(Storage::disk('local')->path($csvPath), 'wb');
        try {
            foreach ($sheet->toArray(null, true, false, false) as $row) {
                fputcsv($out, array_map(static function ($value): string {
                    if (is_float($value) && floor($value) === $value && abs($value) < 1e16) {
                        return sprintf('%.0f', $value);
                    }

                    return trim((string) $value);
                }, $row), ',', '"', '');
            }
        } finally {
            fclose($out);
        }

        return $csvPath;
    }

    public function failed(Throwable $exception): void
    {
        $group = CrmLeadGroup::query()
            ->whereKey($this->groupId)
            ->where('status', 'processing')
            ->first();
        $group?->update([
            'status' => $group->leads()->exists() ? 'completed' : 'failed',
            'error_message' => mb_substr($exception->getMessage(), 0, 250),
        ]);

        Storage::disk('local')->delete($this->path);
    }

    /**
     * @param  list<array<int, string|null>>  $batch
     * @param  array<string, int>  $headerIndexes
     * @param  Collection<string, array<string, mixed>>  $definitions
     * @param  array<string, int>  $phoneLookup
     * @param  callable(string):string  $phoneKey
     * @return array{int,int,int}
     */
    private function importBatch(
        array $batch,
        array $headerIndexes,
        $definitions,
        array &$phoneLookup,
        callable $phoneKey,
        CrmLeadGroup $group,
    ): array {
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $memberRows = [];
        $now = now();
        $existingIds = [];
        foreach ($batch as $values) {
            $phoneIndex = $headerIndexes['phone'];
            $digits = $phoneKey(trim((string) ($values[$phoneIndex] ?? '')));
            if ($digits !== '' && isset($phoneLookup[$digits])) {
                $existingIds[] = $phoneLookup[$digits];
            }
        }
        $leadCache = CrmLead::query()
            ->where('workspace_id', $group->workspace_id)
            ->whereIn('id', array_values(array_unique($existingIds)))
            ->get()
            ->keyBy('id');

        DB::transaction(function () use (
            $batch,
            $headerIndexes,
            $definitions,
            &$phoneLookup,
            $phoneKey,
            $group,
            &$created,
            &$updated,
            &$skipped,
            &$memberRows,
            $now,
            &$leadCache,
        ) {
            foreach ($batch as $values) {
                $get = static function (string $key) use ($headerIndexes, $values): string {
                    $index = $headerIndexes[$key] ?? null;

                    return $index === null ? '' : trim((string) ($values[$index] ?? ''));
                };
                $name = $get('name');
                $phone = $get('phone');
                $phoneDigits = $phoneKey($phone);
                $email = $get('email');
                $company = $get('company');
                $stage = $get('stage');

                if (
                    $name === ''
                    || mb_strlen($name) > 120
                    || $phoneDigits === ''
                    || strlen($phoneDigits) < 7
                    || strlen($phoneDigits) > 15
                    || mb_strlen($phone) > 32
                    || ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL))
                    || mb_strlen($email) > 160
                    || mb_strlen($company) > 120
                    || ($stage !== '' && ! in_array($stage, ['new', 'contacted', 'qualified', 'won', 'lost'], true))
                ) {
                    $skipped++;

                    continue;
                }

                $customFields = [];
                foreach ($definitions as $key => $definition) {
                    $value = $get((string) $key);
                    if ($value !== '') {
                        $customFields[$key] = mb_substr($value, 0, 1000);
                    }
                }

                $leadId = $phoneLookup[$phoneDigits] ?? null;
                $lead = $leadId
                    ? ($leadCache->get($leadId) ?? CrmLead::query()->find($leadId))
                    : null;
                if ($lead) {
                    $attributes = [];
                    foreach (['name' => $name, 'phone' => $phone, 'email' => $email, 'company' => $company] as $field => $value) {
                        if ($value !== '') {
                            $attributes[$field] = $value;
                        }
                    }
                    if ($stage !== '') {
                        $attributes['stage'] = $stage;
                    }
                    if ($customFields !== []) {
                        $attributes['custom_fields'] = array_merge($lead->custom_fields ?? [], $customFields);
                    }
                    $lead->update($attributes);
                    $updated++;
                } else {
                    $lead = CrmLead::query()->create([
                        'workspace_id' => $group->workspace_id,
                        'name' => $name,
                        'phone' => $phone,
                        'email' => $email !== '' ? $email : null,
                        'company' => $company !== '' ? $company : null,
                        'stage' => $stage !== '' ? $stage : 'new',
                        'source' => 'csv_import',
                        'custom_fields' => $customFields,
                    ]);
                    $created++;
                }

                $leadCache->put($lead->id, $lead);
                $phoneLookup[$phoneDigits] = $lead->id;
                $memberRows[] = [
                    'crm_lead_group_id' => $group->id,
                    'crm_lead_id' => $lead->id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            foreach (array_chunk($memberRows, 500) as $membershipChunk) {
                DB::table('crm_lead_group_members')->insertOrIgnore($membershipChunk);
            }
        });

        return [$created, $updated, $skipped];
    }
}
