<?php

namespace App\Services;

use App\Models\DailyPhotoCase;
use App\Models\OcrJob;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use ZipArchive;

class DailyImageArchiveService
{
    private const APPROVED = ['AUTO_APPROVED', 'APPROVED', 'CORRECTED'];

    private array $groupCache = [];

    public function paginate(array $filters, int $perPage = 24): LengthAwarePaginator
    {
        $groups = $this->groups($filters);
        $page = max(1, (int) ($filters['page'] ?? request()->integer('page', 1)));

        return new LengthAwarePaginator(
            $groups->forPage($page, $perPage)->values(),
            $groups->count(),
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()],
        );
    }

    public function summary(array $filters): array
    {
        $groups = $this->groups($filters);

        return [
            'groups' => $groups->count(),
            'images' => $groups->sum('image_count'),
            'complete' => $groups->where('is_complete', true)->count(),
            'incomplete' => $groups->where('is_complete', false)->count(),
        ];
    }

    public function createZip(array $filters): array
    {
        $groups = $this->groups($filters);
        if ($groups->isEmpty()) {
            throw ValidationException::withMessages(['archive' => 'Không có ảnh hằng ngày theo bộ lọc.']);
        }

        $invalid = config('daily_photos.enabled') ? collect() : $groups->where('is_complete', false);
        if ($invalid->isNotEmpty()) {
            throw ValidationException::withMessages([
                'archive' => "Còn {$invalid->count()} máy/ngày thiếu cặp hoặc trùng giờ. Hãy xử lý trước khi xuất.",
            ]);
        }

        $path = tempnam(sys_get_temp_dir(), 'mmtb-daily-images-');
        if ($path === false) {
            throw new RuntimeException('Không thể tạo file ZIP tạm.');
        }

        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            @unlink($path);
            throw new RuntimeException('Không thể mở file ZIP.');
        }

        $exportedCounts = [];
        foreach ($groups as $group) {
            $dayKey = $group['machine_id'].'|'.$group['date'];
            $exportedCounts[$dayKey] ??= 0;
            foreach ($group['sessions'] as $session) {
                foreach ([['job' => $session['start'], 'role' => 'DAU-CA'], ['job' => $session['end'], 'role' => 'CUOI-CA']] as $image) {
                    $job = $image['job'];
                    if (! $job) {
                        continue;
                    }
                    $attachment = $job->attachment;
                    if (! $attachment || ! Storage::disk($attachment->storage_disk)->exists($attachment->storage_path)) {
                        if (config('daily_photos.enabled')) {
                            continue;
                        }
                        $zip->close();
                        @unlink($path);
                        throw ValidationException::withMessages([
                            'archive' => "Không tìm thấy ảnh gốc của OCR job #{$job->id}.",
                        ]);
                    }

                    $extension = strtolower(pathinfo((string) $attachment->original_name, PATHINFO_EXTENSION)) ?: 'jpg';
                    $file = sprintf(
                        '%s/%s/%s/%s_%s_CA-%02d_%s_%s.%s',
                        $this->safe($group['command_center']),
                        $this->safe($group['machine_code']),
                        $group['date'],
                        $group['date'],
                        $this->safe($group['machine_code']),
                        $session['number'],
                        $session['end'] ? $image['role'] : 'MOC-ANH',
                        str_replace(':', '-', substr((string) $job->extracted_time, 0, 5)).'_JOB-'.$job->id,
                        $extension,
                    );
                    $zip->addFromString($file, Storage::disk($attachment->storage_disk)->get($attachment->storage_path));
                    $exportedCounts[$dayKey]++;
                }
            }
        }
        if (config('daily_photos.enabled')) {
            $notes = [];
            foreach ($groups->groupBy(fn ($group) => $group['machine_id'].'|'.$group['date']) as $key => $dailyGroups) {
                $first = $dailyGroups->first();
                $missing = max(0, 4 - $exportedCounts[$key]);
                if ($missing) {
                    $notes[] = "{$first['date_label']} – {$first['machine_code']}: thiếu {$missing} ảnh";
                }
            }
            $zip->addFromString('GHI-CHU-THIEU-ANH.txt', implode("\r\n", $notes));
        }
        $zip->close();

        $month = $filters['month'] ?? now()->format('Y-m');

        return ['path' => $path, 'name' => "anh-dau-cuoi-ca-{$month}.zip"];
    }

    public function groups(array $filters): Collection
    {
        $cacheKey = md5(serialize(collect($filters)->except('page')->sortKeys()->all()));
        if (isset($this->groupCache[$cacheKey])) {
            return $this->groupCache[$cacheKey];
        }

        return $this->groupCache[$cacheKey] = config('daily_photos.enabled')
            ? $this->canonicalGroups($filters)
            : $this->legacyGroups($filters);
    }

    private function legacyGroups(array $filters): Collection
    {
        [$from, $to] = $this->range($filters);

        $jobs = OcrJob::query()
            ->with([
                'attachment:id,storage_disk,storage_path,original_name,mime_type',
                'machine:id,asset_code',
                'machine.assignments' => fn ($query) => $query->with('commandCenter:id,name')->orderBy('time_in'),
            ])
            ->where('document_type', 'DAILY_TIMEMARK')
            ->whereIn('review_status', self::APPROVED)
            ->whereNotNull('machine_id')
            ->whereNotNull('extracted_date')
            ->whereNotNull('extracted_time')
            ->whereBetween('extracted_date', [$from, $to])
            ->when($filters['machine_id'] ?? null, fn ($query, $id) => $query->where('machine_id', $id))
            ->orderBy('machine_id')->orderBy('extracted_date')->orderBy('extracted_time')->orderBy('id')
            ->get();

        return $jobs
            ->groupBy(fn (OcrJob $job) => $job->machine_id.'|'.$job->extracted_date->format('Y-m-d'))
            ->map(function (Collection $dailyJobs): array {
                $first = $dailyJobs->first();
                $date = $first->extracted_date->format('Y-m-d');
                $assignment = $this->assignmentAt($first, $date);
                $times = $dailyJobs->map(fn (OcrJob $job) => substr((string) $job->extracted_time, 0, 5));
                $hasDuplicateTimes = $times->duplicates()->isNotEmpty();
                $sessions = $dailyJobs->chunk(2)->values()->map(function (Collection $pair, int $index): array {
                    $pair = $pair->values();

                    return [
                        'number' => $index + 1,
                        'start' => $pair->get(0),
                        'end' => $pair->get(1),
                    ];
                });
                $isComplete = $dailyJobs->count() % 2 === 0 && ! $hasDuplicateTimes;

                return [
                    'machine_id' => $first->machine_id,
                    'machine_code' => $first->machine?->asset_code ?: $first->asset_code,
                    'date' => $date,
                    'date_label' => $first->extracted_date->format('d/m/Y'),
                    'command_center_id' => $assignment?->command_center_id,
                    'command_center' => $assignment?->commandCenter?->name ?: 'CHUA-XAC-DINH-BCH',
                    'image_count' => $dailyJobs->count(),
                    'session_count' => $sessions->filter(fn (array $session) => $session['end'] !== null)->count(),
                    'has_duplicate_times' => $hasDuplicateTimes,
                    'is_complete' => $isComplete,
                    'sessions' => $sessions,
                ];
            })
            ->filter(fn (array $group) => ! isset($filters['command_center_id'])
                || ! $filters['command_center_id']
                || (int) $group['command_center_id'] === (int) $filters['command_center_id'])
            ->filter(fn (array $group) => ($filters['completeness'] ?? null) === null
                || ($filters['completeness'] === 'complete' ? $group['is_complete'] : ! $group['is_complete']))
            ->sortBy(fn (array $group) => $group['machine_code'].'|'.$group['date'])
            ->values();
    }

    private function canonicalGroups(array $filters): Collection
    {
        [$from, $to] = $this->range($filters);

        return DailyPhotoCase::query()
            ->has('evidenceMemberships')
            ->with([
                'machine:id,asset_code',
                'machineAssignment.commandCenter:id,name',
                'evidenceMemberships.ocrJob.attachment:id,storage_disk,storage_path,original_name,mime_type',
                'intervals.startEvidence.ocrJob.attachment:id,storage_disk,storage_path,original_name,mime_type',
                'intervals.endEvidence.ocrJob.attachment:id,storage_disk,storage_path,original_name,mime_type',
            ])
            ->whereBetween('work_date', [$from, $to])
            ->when($filters['machine_id'] ?? null, fn ($query, $id) => $query->where('machine_id', $id))
            ->orderBy('machine_id')
            ->orderBy('work_date')
            ->get()
            ->map(function (DailyPhotoCase $case): array {
                $sessions = $case->intervals->values()->map(fn ($interval, int $index) => [
                    'number' => $index + 1,
                    'start' => $interval->startEvidence?->ocrJob,
                    'end' => $interval->endEvidence?->ocrJob,
                ]);
                $pairedEvidenceIds = $case->intervals
                    ->flatMap(fn ($interval) => [$interval->start_evidence_id, $interval->end_evidence_id])
                    ->all();
                $unmatchedJobs = $case->evidenceMemberships
                    ->whereNotIn('id', $pairedEvidenceIds)
                    ->sortBy('capture_datetime')
                    ->pluck('ocrJob')
                    ->filter()
                    ->values();
                foreach ($unmatchedJobs as $job) {
                    $sessions->push([
                        'number' => $sessions->count() + 1,
                        'start' => $job,
                        'end' => null,
                    ]);
                }
                $isComplete = $case->status === DailyPhotoCase::STATUS_READY;
                $hasDuplicateTimes = in_array('DUPLICATE_TIMESTAMP', $case->pairing_diagnostics['codes'] ?? [], true);

                return [
                    'machine_id' => $case->machine_id,
                    'machine_code' => $case->machine?->asset_code ?: 'CHUA-XAC-DINH',
                    'date' => $case->work_date->format('Y-m-d'),
                    'date_label' => $case->work_date->format('d/m/Y'),
                    'command_center_id' => $case->machineAssignment?->command_center_id,
                    'command_center' => $case->machineAssignment?->commandCenter?->name ?: 'CHUA-XAC-DINH-BCH',
                    'image_count' => $case->evidenceMemberships->count(),
                    'session_count' => $case->intervals->count(),
                    'has_duplicate_times' => $hasDuplicateTimes,
                    'is_complete' => $isComplete,
                    'status_label' => match ($case->status) {
                        DailyPhotoCase::STATUS_READY => $case->intervals->count().' ca đủ cặp',
                        DailyPhotoCase::STATUS_COLLECTING => 'Chờ thêm ảnh',
                        default => $hasDuplicateTimes ? 'Trùng giờ' : 'Không thể ghép an toàn',
                    },
                    'sessions' => $sessions,
                ];
            })
            ->filter(fn (array $group) => ! isset($filters['command_center_id'])
                || ! $filters['command_center_id']
                || (int) $group['command_center_id'] === (int) $filters['command_center_id'])
            ->filter(fn (array $group) => ($filters['completeness'] ?? null) === null
                || ($filters['completeness'] === 'complete' ? $group['is_complete'] : ! $group['is_complete']))
            ->sortBy(fn (array $group) => $group['machine_code'].'|'.$group['date'])
            ->values();
    }

    private function range(array $filters): array
    {
        if (! empty($filters['date_from']) || ! empty($filters['date_to'])) {
            $from = CarbonImmutable::parse($filters['date_from'] ?? $filters['date_to'])->startOfDay();
            $to = CarbonImmutable::parse($filters['date_to'] ?? $filters['date_from'])->endOfDay();

            return [$from->toDateString(), $to->toDateString()];
        }

        $month = CarbonImmutable::createFromFormat('Y-m', $filters['month'] ?? now()->format('Y-m'));

        return [$month->startOfMonth()->toDateString(), $month->endOfMonth()->toDateString()];
    }

    private function assignmentAt(OcrJob $job, string $date)
    {
        $day = CarbonImmutable::parse($date);

        return $job->machine?->assignments->first(function ($assignment) use ($day): bool {
            $from = CarbonImmutable::parse($assignment->time_in)->startOfDay();
            $to = $assignment->time_out ? CarbonImmutable::parse($assignment->time_out)->endOfDay() : null;

            return $from->lte($day) && (! $to || $to->gte($day));
        });
    }

    private function safe(?string $value): string
    {
        return trim(preg_replace('/[^A-Z0-9_-]+/', '-', strtoupper(Str::ascii($value ?: 'CHUA-XAC-DINH'))), '-');
    }
}
