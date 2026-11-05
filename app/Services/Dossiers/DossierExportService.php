<?php

namespace App\Services\Dossiers;

use App\Models\DossierExport;
use App\Models\DossierVersion;
use App\Models\Machine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DossierExportService
{
    public function preview(Machine $machine, array $drivers, array $ids): array
    {
        $report = app(DossierReport::class);
        $service = app(DossierService::class);
        $service->check(count($drivers) === count(array_unique($drivers)) && ! array_diff($drivers, $report->drivers($machine)->pluck('driver_id')->all()), 'Chọn tài xế đang phân công máy.');
        $service->check(count($ids) > 0 && count($ids) === count(array_unique($ids)) && count($ids) <= 200, 'Chọn giấy không trùng, tối đa 200.');
        $rows = $report->rows($machine, $drivers);
        $allowed = $rows->filter(fn ($r) => $r['version'])->keyBy(fn ($r) => $r['version']->id);
        $warnings = $rows->whereIn('status', ['missing', 'expired', 'review', 'future', 'archived'])->map(fn ($r) => $r['type']->name.': '.$r['subject_name'].' — '.$r['label'])->all();
        $manifest = [];
        $seen = [];
        foreach ($ids as $id) {
            $service->check($allowed->has($id), 'Giấy đã thay thế, tài xế cũ hoặc sai phạm vi; tải lại xem trước.');
            $row = $allowed[$id];
            $v = $row['version'];
            $source = $v->source;
            $service->check($source->status === 'ready', 'File nguồn chưa sẵn sàng.');
            $pages = [];
            foreach ($v->pages as $page) {
                $key = $source->sha256.':'.$page['page'].':'.$page['rotation'];
                if (! isset($seen[$key])) {
                    $seen[$key] = true;
                    $pages[] = $page;
                }
            }
            // Keep every selected version in manifest even when all its shared pages are deduplicated.
            $manifest[] = ['version_id' => $v->id, 'record_id' => $v->record_id, 'source_id' => $source->id,
                'source_object_id' => $source->object_id, 'processed_object_id' => $source->processed_object_id, 'source_path' => $source->path, 'source_sha256' => $source->sha256, 'pages' => $pages,
                'processed_path' => $source->processed_path, 'processed_sha256' => $source->processed_sha256,
                'processing' => $source->processing,
                'original_pages' => $v->pages, 'type' => $row['type']->name, 'subject' => $row['subject'],
                'subject_id' => $row['subject_id'], 'subject_name' => $row['subject_name'],
                'status' => $row['status'], 'number' => $v->number, 'expiry_precision' => $v->expiry_precision,
                'expiry_value' => $v->expiry_value, 'month_rule' => $v->month_rule, 'effective_at' => $v->effective_at,
                'provenance' => $v->provenance, 'health_signed_on' => $v->health_signed_on, 'renewal_on' => $v->renewal_on];
        }
        foreach ($rows->filter(fn ($r) => $r['version'] && in_array($r['status'], ['valid', 'permanent', 'expiring'], true)) as $row) {
            if (! in_array($row['version']->id, $ids, true)) {
                $warnings[] = 'Đã bỏ khỏi bộ: '.$row['type']->name.' / '.$row['subject_name'];
            }
        }

        return ['manifest' => $manifest, 'warnings' => array_values($warnings), 'drivers' => $drivers];
    }

    public function create(Machine $machine, array $drivers, array $ids, string $requestId, bool $acknowledged, int $actor): DossierExport
    {
        $existing = DossierExport::where('request_id', $requestId)->first();
        if ($existing) {
            abort_unless($existing->user_id === $actor && $existing->machine_id === $machine->id, 403);

            return $existing;
        }

        return DB::transaction(function () use ($machine, $drivers, $ids, $requestId, $acknowledged, $actor) {
            // Lock subjects and version records so confirmation/archive cannot race manifest capture.
            Machine::whereKey($machine->id)->lockForUpdate()->firstOrFail();
            $existing = DossierExport::where('request_id', $requestId)->lockForUpdate()->first();
            if ($existing) {
                abort_unless($existing->user_id === $actor && $existing->machine_id === $machine->id, 403);

                return $existing;
            }
            $versions = DossierVersion::whereIn('id', $ids)->get();
            \App\Models\DossierRecord::whereIn('id', $versions->pluck('record_id'))->orderBy('id')->lockForUpdate()->get();
            DossierVersion::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            $preview = $this->preview($machine, $drivers, $ids);
            app(DossierService::class)->check(! $preview['warnings'] || $acknowledged, 'Cần xác nhận đã đọc cảnh báo trước khi xuất.');
            $export = DossierExport::create(['id' => (string) Str::uuid(), 'machine_id' => $machine->id, 'user_id' => $actor,
                'request_id' => $requestId, 'manifest' => $preview['manifest'], 'warnings' => $preview['warnings']]);
            DossierAudit::write('export_requested', $export, $actor);
            app(DossierRemoteJobs::class)->export($export);

            return $export;
        }, 3);
    }
}
