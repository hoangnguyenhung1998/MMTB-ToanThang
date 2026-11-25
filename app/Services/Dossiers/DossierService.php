<?php

namespace App\Services\Dossiers;

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
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DossierService
{
    public function upload(DossierBatch $batch, UploadedFile $file, int $actor, ?DossierVersion $replacement = null, ?array $target = null): array
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
            $this->check(! $existing->driver_reset_retired_at, 'Nguồn đã ngừng sử dụng sau reset tài xế.');
            $this->check(! $replacement || $existing->replacement_version_id === $replacement->id, 'PDF đã thuộc một nguồn khác.');
            $this->check(! $target || $existing->document_target === $target, 'PDF đã thuộc một hồ sơ khác.');

            if ($existing->machine_id) {
                app(\App\Services\DossierZaloRoster::class)->provisionForMachine($existing->machine_id, $actor);
            }

            return ['source' => $existing, 'duplicate' => true];
        }
        $name = basename(str_replace('\\', '/', $file->getClientOriginalName()));
        $resolved = app(AssetCodeResolver::class)->resolve(pathinfo($name, PATHINFO_FILENAME));
        $this->check(! $target || $target['subject'] !== 'machine' || ! $resolved['machine'] || $resolved['machine']->id === $target['id'], 'Mã trên tên file khác máy đã chọn.');
        $objects = app(DossierObjects::class);
        $object = $objects->store($file, 'source');
        try {
            $source = DB::transaction(function () use ($batch, $file, $actor, $hash, $name, $resolved, $object, $replacement, $target) {
                if ($replacement) {
                    $record = DossierRecord::lockForUpdate()->findOrFail($replacement->record_id);
                    $current = app(DossierStatus::class)->current($record->load('versions'));
                    $this->check($current?->id === $replacement->id && ! $current->archived, 'Bản hiện hành đã đổi. Tải lại trước khi thay.');
                    $this->check(! DossierSource::where('replacement_version_id', $replacement->id)->whereNull('replacement_cancelled_at')->exists(),
                        'Đã có bản thay đang xử lý. Mở lại hoặc hủy bản đó trước.');
                }
                if ($target) {
                    $model = $target['subject'] === 'machine' ? Machine::class : Driver::class;
                    $model::lockForUpdate()->findOrFail($target['id']);
                    $this->check($this->canAddToScope(DossierRecord::where('scope_key', $target['scope'])->lockForUpdate()->first()), 'Giấy cùng loại đã có trong hồ sơ này. Mở giấy hiện hành và chọn Thay file.');
                    $this->check(! DossierSource::where('document_target->scope', $target['scope'])->whereDoesntHave('versions', fn ($q) => $q->where('confirmed', true))->lockForUpdate()->exists(), 'Đã có một file nhập cho giấy này. Mở file đang xử lý hoặc thử lại.');
                }
                $machineId = $target ? ($target['subject'] === 'machine' ? $target['id'] : null) : ($replacement ? $replacement->source->machine_id : $resolved['machine']?->id);
                $source = DossierSource::create(['batch_id' => $batch->id, 'machine_id' => $machineId,
                    'replacement_version_id' => $replacement?->id, 'document_target' => $target, 'machine_confirmed' => (bool) ($replacement || $target),
                    'user_id' => $actor, 'original_name' => $name, 'sha256' => $hash, 'path' => $object->key, 'object_id' => $object->id,
                    'bytes' => $file->getSize(), 'resolution' => $replacement && $replacement->record->machine_id && $resolved['machine'] && $resolved['machine']->id !== $replacement->record->machine_id ? 'REPLACEMENT_MISMATCH' : $resolved['status'], 'candidates' => $resolved['candidate_asset_codes']]);
                $object->update(['status' => 'published']);
                DossierAudit::write('source_uploaded', $source, $actor);
                app(DossierRemoteJobs::class)->prepare($source);

                return $source;
            });
        } catch (\Throwable $e) {
            $objects->deleteUnreferencedStaged($object->fresh());
            if ($e instanceof \Illuminate\Database\UniqueConstraintViolationException && ($existing = DossierSource::where('sha256', $hash)->first())) {
                $this->check(! $replacement || $existing->replacement_version_id === $replacement->id, 'PDF đã thuộc một nguồn khác.');
                $this->check(! $target || $existing->document_target === $target, 'PDF đã thuộc một hồ sơ khác.');

                return ['source' => $existing, 'duplicate' => true];
            }
            throw $e;
        }

        if ($source->machine_id) {
            app(\App\Services\DossierZaloRoster::class)->provisionForMachine($source->machine_id, $actor);
        }

        return ['source' => $source->fresh(), 'duplicate' => false];
    }

    public function saveSource(DossierSource $source, array $data, int $actor): void
    {
        $bound = $source->document_target || $source->replacement_version_id;
        $data = Validator::make($data, ['machine_id' => [$bound ? 'nullable' : 'required', 'integer', 'exists:machines,id'],
            'revision' => ['required', 'integer'], 'excluded_pages' => ['present', 'array'],
            'excluded_pages.*' => ['integer', 'min:1'], 'confirm' => ['required', 'boolean']])->validate();
        DB::transaction(function () use ($source, $data, $actor) {
            $s = DossierSource::lockForUpdate()->findOrFail($source->id);
            $this->check(! $s->confirmed && $s->status === 'ready' && ! $s->driver_reset_retired_at, 'Hồ sơ đã xác nhận hoặc PDF chưa sẵn sàng.');
            $this->check($s->revision === (int) $data['revision'], 'Trang đã được sửa ở phiên khác; tải lại trước khi lưu.');
            $versions = DossierVersion::where('source_id', $s->id)->get();
            $fixed = $s->document_target || $s->replacement_version_id;
            $machineId = $fixed ? $s->machine_id : (int) $data['machine_id'];
            $this->check($versions->isEmpty() || $s->machine_id === $machineId, 'Đã phân loại: không thể đổi máy nguồn.');
            $excluded = array_values(array_unique(array_map('intval', $data['excluded_pages'])));
            $this->check(! $excluded || max($excluded) <= $s->page_count, 'Trang bỏ ngoài phạm vi nguồn.');
            $used = $versions->flatMap(fn ($v) => array_column($v->pages, 'page'))->all();
            $this->check(! array_intersect($used, $excluded), 'Trang đã gán giấy không thể đồng thời đánh dấu bỏ.');
            if ($data['confirm']) {
                $this->check($versions->isNotEmpty() && $versions->every('confirmed', true), 'Cần xác nhận từng giấy trước.');
                $this->check(count(array_unique([...$excluded, ...$used])) === $s->page_count, 'Còn trang chưa phân loại/bỏ có chủ đích.');
            }
            $before = $s->toArray();
            $s->update(['machine_id' => $machineId, 'machine_confirmed' => true, 'excluded_pages' => $excluded,
                'confirmed' => $data['confirm'], 'revision' => $s->revision + 1]);
            DossierAudit::write($data['confirm'] ? 'source_confirmed' : 'source_draft_saved', $s, $actor, $before);
        });
        if ($machineId = $source->fresh()->machine_id) {
            app(\App\Services\DossierZaloRoster::class)->provisionForMachine($machineId, $actor);
        }
    }

    public function saveVersion(array $input, int $actor, ?DossierVersion $draft = null, ?array $insuranceEvidence = null, bool $newScopeOnly = false, bool $inlineCorrection = false, bool $fromClassification = false): DossierVersion
    {
        if ($draft?->corrects_id) {
            $input['corrects_id'] = $draft->corrects_id;
        }
        $data = Validator::make($input, [
            'identity_check' => ['nullable', 'array:person_name,birth_date,identity'],
            'identity_check.person_name' => ['nullable', 'string', 'max:255'],
            'identity_check.birth_date' => ['nullable', 'date_format:Y-m-d'],
            'identity_check.identity' => ['nullable', 'string', 'max:12'],
            'bic_page' => ['nullable', 'integer', 'min:1'],
            'bic_quote' => ['nullable', 'string', 'max:2000'],
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
            'expiry_precision' => ['required', 'in:day,minute,month,permanent,unknown,not_tracked'], 'expiry_value' => ['nullable', 'string', 'max:30'],
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

        return DB::transaction(function () use ($data, $actor, $draft, $insuranceEvidence, $newScopeOnly, $inlineCorrection, $fromClassification) {
            if (! $draft && ($existing = DossierVersion::where('request_id', $data['request_id'])->first())) {
                return $existing;
            }
            $source = DossierSource::lockForUpdate()->findOrFail($data['source_id']);
            if (! $draft && ($existing = DossierVersion::where('request_id', $data['request_id'])->lockForUpdate()->first())) {
                return $existing;
            }
            $this->check($source->status === 'ready' && ! $source->driver_reset_retired_at && $source->machine_confirmed, 'Lưu đối chiếu máy trước; nguồn phải sẵn sàng.');
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
            $this->check($data['expiry_precision'] !== 'not_tracked' || ! in_array($type->ai_key, Ai\DossierAiSchema::EXPIRY_TYPES, true), 'Loại giấy này cần trạng thái hạn.');
            $group = $data['subject'] === 'driver' ? 'driver' : $source->machine->dossier_group;
            $this->check($type->supportsGroup($group), 'Loại giấy không áp dụng cho nhóm chủ thể này.');
            $scopeKey = implode(':', [$data['subject'], $subject->id, $type->id, $data['instance']]);
            if ($target = $source->document_target) {
                $this->check($target['subject'] === $data['subject'] && $target['id'] === $subject->id && ($correction || $target['scope'] === $scopeKey), 'Giấy phải thuộc đúng chủ thể và loại đã chọn khi nhập.');
            }
            // Serialize same-subject record creation across uploads.
            $subject->newQuery()->whereKey($subject->id)->lockForUpdate()->firstOrFail();
            // Current locking reads matter under MySQL REPEATABLE READ: the request-id
            // lookup above may have established a snapshot before waiting for this subject.
            $record = DossierRecord::where('scope_key', $scopeKey)->lockForUpdate()->first();
            if ($inlineCorrection) {
                $origin = DossierRecord::lockForUpdate()->findOrFail($correction->record_id);
                $origin->setRelation('versions', $origin->versions()->lockForUpdate()->get());
                $this->check(app(DossierStatus::class)->current($origin)?->id === $correction->id, 'Giấy hiện hành đã đổi. Tải lại hồ sơ.');
                $this->check(! $record || $record->id === $origin->id, 'Loại đích đã có giấy hoặc lịch sử ở cùng phạm vi.');
            }
            if ($target && ! $correction && $record) {
                $this->check($this->canAddToScope($record) || $record->versions()->lockForUpdate()->get()->contains(fn ($v) => $v->source_id === $source->id && ! $v->confirmed), 'Đã có giấy ở phạm vi này. Dùng Thay file từ dòng giấy hiện hành.');
            }
            $this->check(! $newScopeOnly || ! $record, 'Loại giấy đã có trong phạm vi này; cần đối chiếu trước khi thay.');
            $record ??= DossierRecord::create(['scope_key' => $scopeKey,
                'type_id' => $type->id, $data['subject'].'_id' => $subject->id, 'instance' => $data['instance'],
            ]);
            $this->check(! $source->confirmed || $correction || (! empty($data['record_id']) && $record->versions()->where('source_id', $source->id)->exists()), 'Nguồn đã đóng: chỉ được tạo phiên bản sửa thông tin cho giấy đã có.');
            if ($inlineCorrection) {
                $activeIds = $others->groupBy('record_id')->map(fn ($versions) => $versions->where('confirmed', true)
                    ->where('effective_at', '<=', app(DossierStatus::class)->now()->format('Y-m-d H:i:s'))
                    ->sortByDesc(fn ($version) => [$version->effective_at, $version->id])->first()?->id)->all();
                $others = $others->filter(fn ($version) => ! $version->archived && (! $version->confirmed ||
                    $version->effective_at > app(DossierStatus::class)->now()->format('Y-m-d H:i:s') ||
                    in_array($version->id, $activeIds, true)));
            }
            foreach ($others as $other) {
                $this->check($other->record_id === $record->id || $other->record_id === $correction?->record_id || ! array_intersect($nums, array_column($other->pages, 'page')) || ($data['shared_pages'] && $other->shared_pages), 'Trang dùng trùng: xác nhận dùng chung trên cả hai giấy.');
            }
            $this->check(empty($data['record_id']) || (int) $data['record_id'] === $record->id || $correction?->record_id === (int) $data['record_id'] || ($draft && $draft->record_id === (int) $data['record_id']), 'Chuỗi giấy gốc không khớp với yêu cầu sửa.');
            if ($source->replacement_version_id && ! $inlineCorrection) {
                $blanks = $source->latestAiRun?->pages()->where('status', 'ready')->get()
                    ->filter(fn ($page) => ($page->result['kind'] ?? null) === 'blank')->pluck('page')->all() ?? [];
                $expected = array_values(array_diff(range(1, $source->page_count), $blanks, $source->excluded_pages ?? []));
                $selected = $nums;
                sort($selected);
                $this->check($selected === $expected && $expected !== [], 'Bản thay cần đủ mọi trang giấy.');

                $target = $source->replacementVersion;
                $current = app(DossierStatus::class)->current($record->load('versions'));
                $this->check(! $source->replacement_cancelled_at && $target->record_id === $record->id
                    && $current?->id === $target->id && ! $current->archived, 'Nguồn thay đã hủy hoặc loại, chủ thể, bản hiện hành đã đổi.');
            }
            $v = $draft ? DossierVersion::lockForUpdate()->findOrFail($draft->id) : new DossierVersion;
            $this->check(! $v->exists || (! $v->confirmed && $v->source_id === $source->id), 'Phiên bản xác nhận bất biến; tạo phiên bản thay thế.');
            $this->check(! $v->exists || $v->request_id === $data['request_id'], 'Mã nháp không khớp.');
            if ($data['confirmed']) {
                $latest = $record->versions()->where('confirmed', true)->when($v->exists, fn ($q) => $q->where('id', '!=', $v->id))->latest('effective_at')->orderByDesc('id')->lockForUpdate()->first();
                $this->check(! $latest || $data['effective_at'] > $latest->effective_at || ($inlineCorrection && $latest->id === $correction?->id && $data['effective_at'] === $latest->effective_at), 'Phiên bản mới phải hiệu lực sau phiên bản đã xác nhận gần nhất.');
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
            if ($correction && $correction->record_id !== $record->id && $v->provenance) {
                $provenance = $v->provenance;
                unset($provenance['verified_identity'], $provenance['insurance_evidence'], $provenance['insurance_scope']);
                $v->provenance = $provenance;
            }
            if ($data['confirmed']) {
                $provenance = [...($v->provenance ?? []), 'reviewed_by' => $actor, 'reviewed_at' => now()->toIso8601String()];
                $compare = app(Ai\DossierComparison::class);
                if ($type->ai_key === 'identity' && $data['subject'] === 'driver' && array_filter($data['identity_check'] ?? [])) {
                    $provenance['verified_identity'] = $compare->verifyIdentity([...$data['identity_check'], 'subject_id' => $subject->id]);
                }
                if ($type->ai_key === 'bic_personal') {
                    $identity = $data['subject'] === 'driver' ? $compare->identity($subject->id) : [];
                    if (! $insuranceEvidence && ! empty($data['bic_quote'])) {
                        $insuranceEvidence = $compare->insuranceEvidence([
                            'pages' => $nums, 'insured' => [[
                                'page' => $data['bic_page'] ?? 0, 'quote' => $data['bic_quote'],
                                'name' => $identity['person_name'] ?? '', 'identity' => $identity['identity'] ?? '',
                            ]],
                        ], $identity);
                        if ($insuranceEvidence && ! str_contains(mb_strtolower($data['bic_quote']), mb_strtolower($identity['person_name']))) {
                            $insuranceEvidence = null;
                        }
                    }
                    $classified = $fromClassification || ($inlineCorrection && ($provenance['insurance_scope'] ?? null) === 'classification');
                    $this->check($data['subject'] === 'driver' && ($insuranceEvidence ? in_array($insuranceEvidence['page'], $nums, true) : $classified),
                        'Cần chọn đúng tài xế và chỉ rõ trang cùng dòng danh sách BIC có họ tên và số CCCD đã đối chiếu.');
                    if ($insuranceEvidence) {
                        $provenance['insurance_evidence'] = $insuranceEvidence;
                    } else {
                        $provenance['insurance_scope'] = 'classification';
                    }
                }
                $v->provenance = $provenance;
            }
            $v->fill(collect($data)->except(['record_id', 'type_id', 'subject', 'subject_id', 'instance', 'identity_check', 'bic_page', 'bic_quote'])->all());
            if ($inlineCorrection && $correction->record_id === $record->id) {
                $v->fill($correction->only(['health_signed_on', 'renewal_on']));
            }
            $v->fill(['record_id' => $record->id, 'user_id' => $actor, 'pages' => $pages])->save();
            // Moving a draft subject is explicit and audited; empty record remains for traceability.
            if ($inlineCorrection) {
                $before = [...$correction->toArray(), 'type_id' => $origin->type_id, 'new_type_id' => $type->id];
                if ($origin->type->ai_key === 'identity') {
                    unset($before['number']);
                }
            }
            DossierAudit::write($inlineCorrection ? 'version_corrected' : ($data['confirmed'] ? 'version_confirmed' : 'version_draft_saved'), $v, $actor, $before);
            if ($source->confirmed && ! $inlineCorrection) {
                $sourceBefore = $source->toArray();
                $source->update(['confirmed' => false]);
                DossierAudit::write('source_reopened_for_revision', $source, $actor, $sourceBefore);
            }
            $source->increment('revision');

            return $v;
        }, 3);
    }

    private function canAddToScope(?DossierRecord $record): bool
    {
        if (! $record) {
            return true;
        }
        $record->setRelation('versions', $record->versions()->lockForUpdate()->get());
        $status = app(DossierStatus::class);

        return $status->current($record)?->archived === true && ! $record->versions->contains(fn ($v) => ! $v->confirmed || $v->effective_at > $status->now()->format('Y-m-d H:i:s'));
    }

    public function archive(DossierVersion $version, bool $archived, string $reason, int $actor): void
    {
        DB::transaction(function () use ($version, $archived, $reason, $actor) {
            $v = DossierVersion::lockForUpdate()->findOrFail($version->id);
            $before = $v->toArray();
            $record = DossierRecord::whereKey($v->record_id)->lockForUpdate()->firstOrFail();
            $current = app(DossierStatus::class)->current($record->load('versions'));
            $this->check($archived || ! $v->confirmed || $current?->id === $v->id,
                'Đã có bản hiện hành khác. Kiểm tra lịch sử trước khi khôi phục.');
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
