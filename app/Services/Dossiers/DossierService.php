<?php

namespace App\Services\Dossiers;

use App\Jobs\ProcessDossierSource;
use App\Models\DossierBatch;
use App\Models\DossierRecord;
use App\Models\DossierSource;
use App\Models\DossierType;
use App\Models\DossierVersion;
use App\Models\Driver;
use App\Models\Machine;
use App\Models\MachineAssignment;
use App\Models\MachineDriverHistory;
use App\Services\AssetCodeResolver;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DossierService
{
    public function upload(DossierBatch $batch, UploadedFile $file, int $actor): array
    {
        Validator::make(['file' => $file], ['file' => ['required', 'file', 'mimes:pdf', 'max:'.config('dossiers.max_upload_kb')]])->validate();
        $stream = fopen($file->getRealPath(), 'rb');
        try {
            $header = fread($stream, 1024);
        } finally {
            fclose($stream);
        }
        $this->check(str_contains($header, '%PDF-'), 'File thực tế không phải PDF.');
        $hash = hash_file('sha256', $file->getRealPath());
        $existing = DossierSource::where('sha256', $hash)->first();
        if ($existing) {
            return ['source' => $existing, 'duplicate' => true];
        }
        $name = basename(str_replace('\\', '/', $file->getClientOriginalName()));
        $resolved = app(AssetCodeResolver::class)->resolve(pathinfo($name, PATHINFO_FILENAME));
        // Each upload uses a unique path; a concurrent duplicate cannot overwrite original bytes.
        $path = $file->storeAs('sources', Str::uuid().'.pdf', 'dossiers');
        try {
            $source = DB::transaction(function () use ($batch, $file, $actor, $hash, $name, $resolved, $path) {
                $source = DossierSource::create(['batch_id' => $batch->id, 'machine_id' => $resolved['machine']?->id,
                    'user_id' => $actor, 'original_name' => $name, 'sha256' => $hash, 'path' => $path,
                    'bytes' => $file->getSize(), 'resolution' => $resolved['status'], 'candidates' => $resolved['candidate_asset_codes']]);
                DossierAudit::write('source_uploaded', $source, $actor);
                ProcessDossierSource::dispatch($source->id)->afterCommit();

                return $source;
            });
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            Storage::disk('dossiers')->delete($path);
            $existing = DossierSource::where('sha256', $hash)->first();
            if (! $existing) {
                throw $e;
            }

            return ['source' => $existing, 'duplicate' => true];
        } catch (\Throwable $e) {
            // Do not delete a committed source if queue dispatch failed after commit.
            if (! DossierSource::where('path', $path)->exists()) {
                Storage::disk('dossiers')->delete($path);
            }
            throw $e;
        }

        return ['source' => $source, 'duplicate' => false];
    }

    public function saveSource(DossierSource $source, array $data, int $actor): void
    {
        $data = Validator::make($data, ['machine_id' => ['required', 'integer', 'exists:machines,id'],
            'revision' => ['required', 'integer'], 'excluded_pages' => ['present', 'array'],
            'excluded_pages.*' => ['integer', 'min:1'], 'confirm' => ['required', 'boolean']])->validate();
        DB::transaction(function () use ($source, $data, $actor) {
            $s = DossierSource::lockForUpdate()->findOrFail($source->id);
            $this->check(! $s->confirmed && $s->status === 'ready', 'Hồ sơ đã xác nhận hoặc PDF chưa sẵn sàng.');
            $this->check($s->revision === (int) $data['revision'], 'Trang đã được sửa ở phiên khác; tải lại trước khi lưu.');
            $versions = DossierVersion::where('source_id', $s->id)->get();
            $this->check($versions->isEmpty() || $s->machine_id === (int) $data['machine_id'], 'Đã phân loại: không thể đổi máy nguồn.');
            $excluded = array_values(array_unique(array_map('intval', $data['excluded_pages'])));
            $this->check(! $excluded || max($excluded) <= $s->page_count, 'Trang bỏ ngoài phạm vi nguồn.');
            $used = $versions->flatMap(fn ($v) => array_column($v->pages, 'page'))->all();
            $this->check(! array_intersect($used, $excluded), 'Trang đã gán giấy không thể đồng thời đánh dấu bỏ.');
            if ($data['confirm']) {
                $this->check($versions->isNotEmpty() && $versions->every('confirmed', true), 'Cần xác nhận từng giấy trước.');
                $this->check(count(array_unique([...$excluded, ...$used])) === $s->page_count, 'Còn trang chưa phân loại/bỏ có chủ đích.');
            }
            $before = $s->toArray();
            $s->update(['machine_id' => $data['machine_id'], 'machine_confirmed' => true, 'excluded_pages' => $excluded,
                'confirmed' => $data['confirm'], 'revision' => $s->revision + 1]);
            DossierAudit::write($data['confirm'] ? 'source_confirmed' : 'source_draft_saved', $s, $actor, $before);
        });
    }

    public function saveVersion(array $input, int $actor, ?DossierVersion $draft = null): DossierVersion
    {
        if ($draft?->corrects_id) {
            $input['corrects_id'] = $draft->corrects_id;
        }
        $data = Validator::make($input, [
            'request_id' => ['required', 'uuid'], 'source_id' => ['required', 'integer', 'exists:dossier_sources,id'],
            'record_id' => ['nullable', 'integer', 'exists:dossier_records,id'],
            'corrects_id' => ['nullable', 'integer', 'exists:dossier_versions,id'],
            'type_id' => ['required', 'integer', 'exists:dossier_types,id'], 'subject' => ['required', 'in:machine,driver,assignment'],
            'subject_id' => ['required', 'integer'], 'instance' => ['required', 'string', 'max:100'],
            'pages' => ['required', 'array', 'min:1', 'max:300'], 'pages.*.page' => ['required', 'integer', 'min:1'],
            'pages.*.rotation' => ['required', Rule::in([0, 90, 180, 270])],
            'shared_pages' => ['required', 'boolean'], 'number' => ['nullable', 'string', 'max:255'],
            'issuer' => ['nullable', 'string', 'max:255'], 'issued_on' => ['nullable', 'date_format:Y-m-d'],
            'certified_on' => ['nullable', 'date_format:Y-m-d'], 'effective_at' => ['required', 'date_format:Y-m-d H:i:s'],
            'health_signed_on' => ['nullable', 'date_format:Y-m-d'], 'renewal_on' => ['nullable', 'date_format:Y-m-d'],
            'expiry_precision' => ['required', 'in:day,minute,month,permanent,unknown'], 'expiry_value' => ['nullable', 'string', 'max:30'],
            'month_rule' => ['nullable', 'in:end_of_month'], 'note' => ['nullable', 'string', 'max:5000'],
            'reason' => ['required', 'string', 'max:2000'], 'confirmed' => ['required', 'boolean'],
        ])->validate();
        $format = ['day' => 'Y-m-d', 'minute' => 'Y-m-d H:i:s', 'month' => 'Y-m'][$data['expiry_precision']] ?? null;
        if ($format) {
            Validator::make($data, ['expiry_value' => ['required', 'date_format:'.$format]])->validate();
        } else {
            $data['expiry_value'] = null;
        }
        if ($data['expiry_precision'] !== 'month') {
            $data['month_rule'] = null;
        }

        return DB::transaction(function () use ($data, $actor, $draft) {
            if (! $draft && ($existing = DossierVersion::where('request_id', $data['request_id'])->first())) {
                return $existing;
            }
            $source = DossierSource::lockForUpdate()->findOrFail($data['source_id']);
            if (! $draft && ($existing = DossierVersion::where('request_id', $data['request_id'])->lockForUpdate()->first())) {
                return $existing;
            }
            $this->check($source->status === 'ready' && $source->machine_confirmed, 'Lưu đối chiếu máy trước; nguồn phải sẵn sàng.');
            $correction = empty($data['corrects_id']) ? null : DossierVersion::where('source_id', $source->id)->where('confirmed', true)->lockForUpdate()->findOrFail($data['corrects_id']);
            $this->check(! $correction?->archived, 'Giấy sửa đã lưu trữ; tải lại và kiểm tra chuỗi hiện tại.');
            $pages = array_map(fn ($p) => ['page' => (int) $p['page'], 'rotation' => (int) $p['rotation']], $data['pages']);
            $nums = array_column($pages, 'page');
            $this->check(count($nums) === count(array_unique($nums)) && max($nums) <= $source->page_count, 'Trang trùng hoặc ngoài nguồn.');
            $this->check(! array_intersect($nums, $source->excluded_pages ?? []), 'Trang đã đánh dấu bỏ khỏi bộ xuất.');
            $others = DossierVersion::where('source_id', $source->id)->when($draft, fn ($q) => $q->where('id', '!=', $draft->id))->orderBy('id')->lockForUpdate()->get();
            $subject = match ($data['subject']) {
                'machine' => Machine::findOrFail($data['subject_id']),
                'driver' => Driver::findOrFail($data['subject_id']),
                'assignment' => MachineAssignment::findOrFail($data['subject_id']),
            };
            $this->check($data['subject'] === 'driver' || ($data['subject'] === 'machine' ? $subject->id : $subject->machine_id) === $source->machine_id, 'Chủ thể khác máy nguồn.');
            $type = DossierType::findOrFail($data['type_id']);
            $group = $data['subject'] === 'driver' ? 'driver' : $source->machine->dossier_group;
            $this->check(in_array($group, $type->groups, true), 'Loại giấy không áp dụng cho nhóm chủ thể này.');
            $scopeKey = implode(':', [$data['subject'], $subject->id, $type->id, $data['instance']]);
            // Serialize same-subject record creation across uploads.
            $subject->newQuery()->whereKey($subject->id)->lockForUpdate()->firstOrFail();
            // Current locking reads matter under MySQL REPEATABLE READ: the request-id
            // lookup above may have established a snapshot before waiting for this subject.
            $record = DossierRecord::where('scope_key', $scopeKey)->lockForUpdate()->first();
            $record ??= DossierRecord::create(['scope_key' => $scopeKey,
                'type_id' => $type->id, $data['subject'].'_id' => $subject->id, 'instance' => $data['instance'],
            ]);
            $this->check(! $source->confirmed || $correction || (! empty($data['record_id']) && $record->versions()->where('source_id', $source->id)->exists()), 'Nguồn đã đóng: chỉ được tạo phiên bản sửa thông tin cho giấy đã có.');
            foreach ($others as $other) {
                $this->check($other->record_id === $record->id || $other->record_id === $correction?->record_id || ! array_intersect($nums, array_column($other->pages, 'page')) || ($data['shared_pages'] && $other->shared_pages), 'Trang dùng trùng: xác nhận dùng chung trên cả hai giấy.');
            }
            $this->check(empty($data['record_id']) || (int) $data['record_id'] === $record->id || $correction?->record_id === (int) $data['record_id'] || ($draft && $draft->record_id === (int) $data['record_id']), 'Chuỗi giấy gốc không khớp với yêu cầu sửa.');
            $v = $draft ? DossierVersion::lockForUpdate()->findOrFail($draft->id) : new DossierVersion;
            $this->check(! $v->exists || (! $v->confirmed && $v->source_id === $source->id), 'Phiên bản xác nhận bất biến; tạo phiên bản thay thế.');
            $this->check(! $v->exists || $v->request_id === $data['request_id'], 'Mã nháp không khớp.');
            if ($data['confirmed']) {
                $latest = $record->versions()->where('confirmed', true)->when($v->exists, fn ($q) => $q->where('id', '!=', $v->id))->latest('effective_at')->lockForUpdate()->first();
                $this->check(! $latest || $data['effective_at'] > $latest->effective_at, 'Phiên bản mới phải hiệu lực sau phiên bản đã xác nhận gần nhất.');
                $v->replaces_id = $latest?->id;
                if ($correction && $correction->record_id !== $record->id) {
                    // Subject correction is an explicit archive + new scope, never an in-place rewrite.
                    $this->check($data['effective_at'] <= app(DossierStatus::class)->now()->format('Y-m-d H:i:s'), 'Sửa chủ thể phải có hiệu lực hiện tại; không chuyển chủ thể tương lai.');
                    DossierRecord::whereKey($correction->record_id)->lockForUpdate()->firstOrFail();
                    foreach (DossierVersion::where('record_id', $correction->record_id)->where('confirmed', true)->lockForUpdate()->get() as $old) {
                        $oldBefore = $old->toArray();
                        $old->update(['archived' => true]);
                        $oldBefore['correction_reason'] = $data['reason'];
                        $oldBefore['new_record_id'] = $record->id;
                        DossierAudit::write('subject_corrected_archived', $old, $actor, $oldBefore);
                    }
                }
            }
            $before = $v->exists ? $v->toArray() : [];
            if ($v->provenance || $correction?->provenance) {
                $v->provenance = [...($v->provenance ?? $correction->provenance), 'expiry_origin' => 'user_corrected', 'reviewed_by' => $actor,
                    'reviewed_at' => now()->toIso8601String(), 'reason' => $data['reason']];
            }
            $v->fill(collect($data)->except(['record_id', 'type_id', 'subject', 'subject_id', 'instance'])->all());
            $v->fill(['record_id' => $record->id, 'user_id' => $actor, 'pages' => $pages])->save();
            // Moving a draft subject is explicit and audited; empty record remains for traceability.
            DossierAudit::write($data['confirmed'] ? 'version_confirmed' : 'version_draft_saved', $v, $actor, $before);
            if ($source->confirmed) {
                $sourceBefore = $source->toArray();
                $source->update(['confirmed' => false]);
                DossierAudit::write('source_reopened_for_revision', $source, $actor, $sourceBefore);
            }
            $source->increment('revision');

            return $v;
        }, 3);
    }

    public function archive(DossierVersion $version, bool $archived, string $reason, int $actor): void
    {
        DB::transaction(function () use ($version, $archived, $reason, $actor) {
            $v = DossierVersion::lockForUpdate()->findOrFail($version->id);
            $before = $v->toArray();
            $v->update(['archived' => $archived]);
            $before['operation_reason'] = $reason;
            DossierAudit::write($archived ? 'archived' : 'restored', $v, $actor, $before);
        });
    }

    public function assign(Machine $machine, Driver $driver, string $start, ?string $end, int $actor): void
    {
        DB::transaction(function () use ($machine, $driver, $start, $end, $actor) {
            Machine::whereKey($machine->id)->lockForUpdate()->firstOrFail();
            Driver::whereKey($driver->id)->lockForUpdate()->firstOrFail();
            $q = MachineDriverHistory::where('machine_id', $machine->id)->where('driver_id', $driver->id)
                ->where(fn ($q) => $q->whereNull('ended_at')->orWhere('ended_at', '>', $start))
                ->when($end, fn ($q) => $q->where('started_at', '<', $end));
            $this->check(! $q->exists(), 'Phân công của tài xế này bị trùng thời gian.');
            $history = MachineDriverHistory::create(['machine_id' => $machine->id, 'driver_id' => $driver->id, 'started_at' => $start, 'ended_at' => $end]);
            DossierAudit::write('driver_assigned', $history, $actor);
        });
    }

    public function check(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['dossier' => $message]);
        }
    }
}
