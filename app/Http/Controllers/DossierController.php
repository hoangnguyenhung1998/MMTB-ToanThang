<?php

namespace App\Http\Controllers;

use App\Exports\DossierWorkbook;
use App\Models\DossierBatch;
use App\Models\DossierExport;
use App\Models\DossierRecord;
use App\Models\DossierSenderProfile;
use App\Models\DossierSource;
use App\Models\DossierType;
use App\Models\DossierVersion;
use App\Models\Driver;
use App\Models\Machine;
use App\Models\MachineDriverHistory;
use App\Models\ZaloMessage;
use App\Services\Dossiers\DossierAudit;
use App\Services\Dossiers\DossierExportService;
use App\Services\Dossiers\DossierProgress;
use App\Services\Dossiers\DossierReport;
use App\Services\Dossiers\DossierService;
use App\Services\Dossiers\DossierStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

class DossierController extends Controller
{
    public function index(Request $request, DossierReport $report)
    {
        if ($request->query('tab') === 'drivers') {
            return $this->drivers();
        }
        if ($request->query('tab') === 'batches') {
            return view('dossiers.index', ['batches' => DossierBatch::withCount(['sources' => fn ($q) => $q->whereNull('driver_reset_retired_at')])->latest()->paginate(25)->withQueryString()]);
        }
        $machines = Machine::orderBy('asset_code')->when($request->filled('q'), fn ($q) => $q->where('asset_code', 'like', '%'.$request->string('q').'%'))->get();
        $report->warm($machines);
        $sources = DossierSource::whereNull('replacement_cancelled_at')->with(['replacementVersion.record', 'latestAiRun' => fn ($q) => $q->withCount([
            'proposals as needs_review' => fn ($p) => $p->whereNull('version_id')->whereColumn('generation', 'dossier_ai_runs.generation'),
        ])])->whereHas('latestAiRun', fn ($q) => $q->where('status', 'ready'))->get();
        $byDriver = $sources->groupBy(fn ($s) => $s->replacementVersion?->record?->driver_id ?? (($s->document_target['subject'] ?? null) === 'driver' ? $s->document_target['id'] : null));
        $byMachine = $byDriver->get('', collect())->groupBy('machine_id');
        $items = $machines->map(function ($machine) use ($report, $byDriver, $byMachine) {
            $rows = $report->rows($machine);
            $drivers = $report->drivers($machine);
            $pending = $byMachine->get($machine->id, collect())->concat($drivers->flatMap(fn ($h) => $byDriver->get($h->driver_id, collect())));

            $counts = $report->summary($rows);
            $counts['review'] = ($counts['review'] ?? 0) + $pending->sum('latestAiRun.needs_review');

            return compact('machine', 'drivers', 'counts');
        });
        $totals = [];
        foreach (array_keys(DossierStatus::LABELS) as $s) {
            $totals[$s] = $items->sum(fn ($i) => $i['counts'][$s] ?? 0);
        }
        if ($request->filled('status')) {
            $items = $items->filter(fn ($i) => ($i['counts'][$request->string('status')->toString()] ?? 0) > 0);
        }

        return view('dossiers.index', ['items' => $this->paginate($items), 'totals' => $totals, 'labels' => DossierStatus::LABELS, 'batches' => null]);
    }

    public function batch(Request $request)
    {
        $batch = DossierBatch::create(['id' => (string) Str::uuid(), 'user_id' => $request->user()->id, 'auto_analyze' => $request->has('auto_analyze') ? $request->boolean('auto_analyze') : null]);

        return redirect()->route('dossiers.batch', $batch);
    }

    public function showBatch(DossierBatch $batch)
    {
        $sources = DossierSource::with('machine')->whereNull('driver_reset_retired_at')->where('batch_id', $batch->id)->orderBy('id')->paginate(20, ['*'], 'files_page')->withQueryString();
        $progress = app(DossierProgress::class)->forSources($sources->getCollection());

        return request()->boolean('progress') ? $this->progressResponse($progress) : view('dossiers.batch', compact('batch', 'progress', 'sources'));
    }

    public function upload(Request $request, DossierBatch $batch, DossierService $service)
    {
        // Browser sends one file at a time so one invalid PDF never aborts the batch or loads all files into RAM.
        try {
            $result = $service->upload($batch, $request->file('file'), $request->user()->id);

            return response()->json(['id' => $result['source']->id, 'duplicate' => $result['duplicate'], 'url' => route('dossiers.source', $result['source'])]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('dossier.upload_failed', ['exception' => get_class($e)]);

            return response()->json(['message' => 'Không lưu được file. Các file khác trong đợt vẫn được giữ; thử lại riêng file này.'], 422);
        }
    }

    public function source(DossierSource $source)
    {
        abort_if($source->driver_reset_retired_at, 404);
        $progress = app(DossierProgress::class)->forSources(DossierSource::with('machine')->whereKey($source)->get());
        if (request()->boolean('progress')) {
            return $this->progressResponse($progress);
        }
        $replacement = request()->filled('replace') ? DossierVersion::with('record')->where('source_id', $source->id)->where('confirmed', true)->findOrFail(request('replace')) : null;

        $roster = app(\App\Services\DossierZaloRoster::class);
        $applicants = $roster->applicants($source);

        return view('dossiers.source', ['source' => $source, 'progress' => $progress, 'machines' => Machine::orderBy('asset_code')->get(),
            'aiAvailable' => \Illuminate\Support\Facades\Schema::hasTable('dossier_ai_runs'),
            'aiRun' => \Illuminate\Support\Facades\Schema::hasTable('dossier_ai_runs') ? \App\Models\DossierAiRun::with(['pages', 'proposals'])->where('source_id', $source->id)->latest('id')->first() : null,
            'replacement' => $replacement,
            'senderIdentities' => app(\App\Services\DossierZaloRoster::class)->identities()->filter(fn ($ids) => $ids->count() === 1),
            'driverLabels' => $roster->labels($applicants),
            'drivers' => $applicants, 'types' => DossierType::orderBy('sort_order')->get(),
            'assignments' => $source->machine?->assignments()->with(['project', 'commandCenter'])->get() ?? collect(),
            'versions' => DossierVersion::with(['record.type', 'record.driver', 'record.assignment'])->where('source_id', $source->id)->get()]);
    }

    private function paginate(\Illuminate\Support\Collection $items): \Illuminate\Pagination\LengthAwarePaginator
    {
        $page = \Illuminate\Pagination\Paginator::resolveCurrentPage();

        return (new \Illuminate\Pagination\LengthAwarePaginator($items->forPage($page, 20)->values(), $items->count(), 20, $page,
            ['path' => request()->url()]))->withQueryString();
    }

    private function progressResponse($progress)
    {
        return response()->json(['html' => view('dossiers.progress-rows', compact('progress'))->render(), 'active' => $progress->contains('pending', true)], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function saveSource(Request $request, DossierSource $source, DossierService $service)
    {
        $service->saveSource($source, [...$request->all(), 'excluded_pages' => $request->input('excluded_pages', []), 'confirm' => $request->boolean('confirm')], $request->user()->id);

        return back()->with('success', 'Đã lưu đối chiếu / phân loại nguồn.');
    }

    public function retrySource(Request $request, DossierSource $source)
    {
        abort_if($source->driver_reset_retired_at, 404);
        DB::transaction(function () use ($source, $request) {
            $s = DossierSource::lockForUpdate()->findOrFail($source->id);
            abort_if($s->status === 'ready' || $s->replacement_cancelled_at, 422);
            $before = $s->toArray();
            $s->update(['status' => 'queued', 'error' => null]);
            app(\App\Services\Dossiers\DossierRemoteJobs::class)->prepare($s);
            DossierAudit::write('source_retry', $s, $request->user()->id, $before);
        });

        return back()->with('success', 'Đã xếp hàng tiếp tục từ trang lưu gần nhất.');
    }

    public function file(DossierSource $source, string $kind, ?int $page = null)
    {
        abort_if($source->driver_reset_retired_at, 404);
        abort_unless(in_array($kind, ['source', 'processed', 'thumbnail'], true), 404);
        $id = match ($kind) {
            'source' => $source->object_id, 'processed' => $source->processed_object_id, default => $source->previews[$page] ?? null
        };
        if ($id) {
            return app(\App\Services\Dossiers\DossierObjects::class)->response(\App\Models\DossierObject::findOrFail($id), $kind === 'thumbnail' ? 'page.png' : $source->original_name);
        }
        // Explicit legacy disk only: old references must never be interpreted as R2 keys.
        $path = match ($kind) {
            'source' => $source->path, 'processed' => $source->processed_path, default => "thumbnails/{$source->id}/{$page}.png"
        };
        abort_unless($path && ($kind !== 'thumbnail' || ($page > 0 && $page <= $source->progress)), 404);

        return Storage::disk('dossiers')->response($path, $kind === 'thumbnail' ? 'page.png' : $source->original_name, ['Cache-Control' => 'private, no-store', 'Content-Security-Policy' => 'sandbox', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function version(Request $request, DossierService $service, ?DossierVersion $version = null)
    {
        $data = $request->all();
        if (is_string($request->input('page_numbers'))) {
            $numbers = preg_split('/[\s,;]+/', trim($request->input('page_numbers')), -1, PREG_SPLIT_NO_EMPTY);
            $data['pages'] = array_map(fn ($p) => ['page' => $p, 'rotation' => (int) $request->input('rotations.'.$p, 0)], $numbers);
        }
        $data['effective_at'] = str_replace('T', ' ', $data['effective_at'] ?? '');
        if (strlen($data['effective_at']) === 16) {
            $data['effective_at'] .= ':00';
        }
        if (($data['expiry_precision'] ?? '') === 'minute') {
            $data['expiry_value'] = str_replace('T', ' ', $data['expiry_value'] ?? '');
            if (strlen($data['expiry_value']) === 16) {
                $data['expiry_value'] .= ':00';
            }
        }
        $data['confirmed'] = $request->boolean('confirmed');
        $data['shared_pages'] = $request->boolean('shared_pages');
        $v = $service->saveVersion($data, $request->user()->id, $version);

        return redirect()->route('dossiers.source', $v->source_id)->with('success', 'Đã lưu phiên bản #'.$v->id);
    }

    public function replace(Request $request, DossierVersion $version, DossierService $service)
    {
        if (! $request->isMethod('post')) {
            return view('dossiers.replace', ['version' => $version, 'pending' => DossierSource::where('replacement_version_id', $version->id)
                ->whereNull('replacement_cancelled_at')->first()]);
        }
        $request->validate(['file' => 'required|file']);
        $batch = DossierBatch::create(['id' => (string) Str::uuid(), 'user_id' => $request->user()->id, 'auto_analyze' => true]);
        $result = $service->upload($batch, $request->file('file'), $request->user()->id, $version);

        if ($request->expectsJson()) {
            return response()->json(['url' => route('dossiers.source', $result['source']), 'duplicate' => $result['duplicate']]);
        }

        return redirect()->route('dossiers.source', $result['source']);
    }

    public function addMachineDocument(Request $request, Machine $machine, DossierService $service)
    {
        return $this->addDocument($request, $service, 'machine', $machine->id, $machine->dossier_group, $machine->asset_code);
    }

    public function addDriverDocument(Request $request, Driver $driver, DossierService $service)
    {
        return $this->addDocument($request, $service, 'driver', $driver->id, 'driver', $driver->name);
    }

    private function addDocument(Request $request, DossierService $service, string $subject, int $id, string $group, string $name)
    {
        if ($request->isMethod('get')) {
            $types = DossierType::orderBy('sort_order')->get()->filter(fn ($type) => $type->supportsGroup($group));
            $existing = DossierRecord::where($subject.'_id', $id)->where('instance', 'main')->with('versions')->get()
                ->mapWithKeys(fn ($record) => [$record->type_id => app(DossierStatus::class)->current($record)])->filter(fn ($v) => $v && ! $v->archived);

            return view('dossiers.add', compact('types', 'existing', 'name'));
        }
        $data = $request->validate(['type_id' => ['required', 'integer', 'exists:dossier_types,id'], 'file' => 'required|file']);
        $type = DossierType::findOrFail($data['type_id']);
        $service->check($type->supportsGroup($group), 'Loại giấy không áp dụng cho hồ sơ này.');
        $target = ['subject' => $subject, 'id' => $id, 'type_id' => $type->id, 'scope' => "$subject:$id:{$type->id}:main"];
        $actor = $request->user()->id;
        $batch = DossierBatch::create(['id' => (string) Str::uuid(), 'user_id' => $actor, 'auto_analyze' => $request->boolean('auto_analyze')]);
        try {
            $result = $service->upload($batch, $request->file('file'), $actor, target: $target);
        } finally {
            DossierBatch::whereKey($batch)->doesntHave('sources')->delete();
        }

        return $request->expectsJson() ? response()->json(['url' => route('dossiers.source', $result['source']), 'duplicate' => $result['duplicate']]) : redirect()->route('dossiers.source', $result['source']);
    }

    public function correct(Request $request, DossierVersion $version, DossierService $service)
    {
        $data = $request->validate(['request_id' => 'required|uuid', 'type_id' => 'required|integer|exists:dossier_types,id', 'reason' => 'required|string|max:2000',
            'page_order' => 'nullable|string|max:1200', 'expiry_precision' => 'nullable|in:unknown,day,month,minute,permanent', 'expiry_value' => 'nullable|string|max:30', 'month_rule' => 'nullable|in:end_of_month']);
        $record = $version->record;
        $type = DossierType::findOrFail($data['type_id']);
        $tracked = in_array($type->ai_key, \App\Services\Dossiers\Ai\DossierAiSchema::EXPIRY_TYPES, true);
        $subject = $record->driver_id ? 'driver' : ($record->assignment_id ? 'assignment' : 'machine');
        $metadata = $version->only(['source_id', 'pages', 'shared_pages', 'number', 'issuer', 'issued_on', 'certified_on', 'note']);
        if (isset($data['page_order'])) {
            $pages = preg_split('/[\s,]+/', trim($data['page_order']), -1, PREG_SPLIT_NO_EMPTY);
            validator(['pages' => $pages], ['pages' => 'required|array|max:300', 'pages.*' => 'required|integer|min:1|distinct'])->validate();
            $rotations = array_column($version->pages, 'rotation', 'page');
            $metadata['pages'] = array_map(fn ($page) => ['page' => (int) $page, 'rotation' => $rotations[$page] ?? 0], $pages);
            unset($data['page_order']);
        }
        if ($record->type->ai_key === 'identity' && $record->type_id !== $type->id) {
            $metadata['number'] = null;
        }
        DB::transaction(function () use ($service, $metadata, $data, $record, $version, $subject, $tracked, $request, $type) {
            $updated = $service->saveVersion([
                ...$metadata, ...$data,
                'record_id' => $record->id, 'corrects_id' => $version->id, 'subject' => $subject, 'subject_id' => $record->{$subject.'_id'},
                'instance' => $record->instance, 'confirmed' => true, 'effective_at' => app(DossierStatus::class)->now()->format('Y-m-d H:i:s'),
                'expiry_precision' => $tracked ? ($data['expiry_precision'] ?? 'unknown') : 'not_tracked',
            ], $request->user()->id, insuranceEvidence: $type->id === $record->type_id ? ($version->provenance['insurance_evidence'] ?? null) : null, inlineCorrection: true);
            if (\Illuminate\Support\Facades\Schema::hasTable('dossier_ai_proposals')) {
                $run = $updated->source->latestAiRun;
                $proposal = $run?->proposals()->where('generation', $run->generation)->where('version_id', $version->id)->first();
                if ($proposal) {
                    $classification = $proposal->data;
                    if ($record->type_id !== $type->id || $version->pages !== $updated->pages) {
                        $classification = app(\App\Services\Dossiers\Ai\DossierAiSchema::class)->classification($type, array_column($updated->pages, 'page'), $run->context);
                    }
                    unset($classification['information_pages']);
                    $proposal->update(['version_id' => $updated->id, 'data' => [...$classification,
                        'classification_edited' => true, 'information_revision' => ($proposal->data['information_revision'] ?? 0) + 1]]);
                }
            }
        }, 3);

        return back()->with('success', 'Đã sửa giấy và ghi lịch sử phiên bản.');
    }

    public function cancelReplacement(Request $request, DossierSource $source, DossierService $service)
    {
        DB::transaction(function () use ($request, $source, $service) {
            $locked = DossierSource::lockForUpdate()->findOrFail($source->id);
            $service->check($locked->replacement_version_id && ! DossierVersion::where('source_id', $locked->id)->where('confirmed', true)->exists(),
                'Chỉ hủy bản thay đang chờ.');
            if (! $locked->replacement_cancelled_at) {
                $before = $locked->toArray();
                $locked->update(['replacement_cancelled_at' => now()]);
                DossierAudit::write('replacement_cancelled', $locked, $request->user()->id, $before);
            }
        }, 3);

        return back()->with('success', 'Đã hủy bản thay. Bản hiện hành giữ nguyên.');
    }

    public function archive(Request $request, DossierVersion $version, DossierService $service)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000'], 'archived' => ['required', 'boolean']]);
        $service->archive($version, (bool) $data['archived'], $data['reason'], $request->user()->id);

        return back()->with('success', 'Đã ghi lịch sử lưu trữ / khôi phục.');
    }

    public function machine(Machine $machine, DossierReport $report)
    {
        $drivers = $report->drivers($machine);
        $progress = $this->subjectProgress($machine->id, $drivers->pluck('driver_id')->all());
        if (request()->boolean('progress')) {
            return $this->progressResponse($progress);
        }

        $sources = \Illuminate\Support\Facades\Schema::hasTable('dossier_ai_runs')
            ? DossierSource::where('machine_id', $machine->id)->where('status', 'ready')->whereNull('driver_reset_retired_at')
                ->whereNull('replacement_cancelled_at')->with(['latestAiRun.pages', 'latestAiRun.proposals'])->latest('id')->get()
            : collect();
        $papers = $sources->flatMap(fn ($source) => $source->latestAiRun?->proposals->where('generation', $source->latestAiRun->generation) ?? []);
        $byVersion = $papers->whereNotNull('version_id')->keyBy('version_id');

        return view('dossiers.machine', ['machine' => $machine, 'sources' => $sources, 'papers' => $papers, 'byVersion' => $byVersion, 'rows' => $report->rows($machine), 'drivers' => $drivers, 'progress' => $progress, 'editTypes' => DossierType::orderBy('sort_order')->get(),
            'driverLabels' => app(\App\Services\DossierZaloRoster::class)->labels($drivers->pluck('driver')),
            'allDrivers' => Driver::orderBy('name')->get(), 'histories' => $machine->driverHistories()->with('driver')->latest('started_at')->get(),
            'exports' => DossierExport::where('machine_id', $machine->id)->where('user_id', auth()->id())->latest()->limit(20)->get(),
            'records' => DossierRecord::with(['type', 'versions'])->where('machine_id', $machine->id)->get()]);
    }

    public function group(Request $request, Machine $machine)
    {
        $data = $request->validate(['dossier_group' => ['required', 'in:machine,vehicle']]);
        DB::transaction(function () use ($machine, $data, $request) {
            $before = $machine->toArray();
            $machine->update($data);
            DossierAudit::write('group_updated', $machine, $request->user()->id, $before);
        });

        return back();
    }

    public function assign(Request $request, Machine $machine, DossierService $service)
    {
        $d = $request->validate(['driver_id' => ['required', 'integer', 'exists:drivers,id'], 'started_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'ended_at' => ['nullable', 'date_format:Y-m-d\TH:i', 'after:started_at']]);
        $service->assign($machine, Driver::findOrFail($d['driver_id']), str_replace('T', ' ', $d['started_at']).':00',
            empty($d['ended_at']) ? null : str_replace('T', ' ', $d['ended_at']).':00', $request->user()->id);

        return back()->with('success', 'Đã thêm phân công; giữ nguyên các tài xế khác.');
    }

    public function closeAssignment(Request $request, MachineDriverHistory $history, DossierService $service)
    {
        $d = $request->validate(['ended_at' => ['required', 'date_format:Y-m-d\TH:i']]);
        DB::transaction(function () use ($history, $d, $request, $service) {
            Machine::whereKey($history->machine_id)->lockForUpdate()->firstOrFail();
            $h = MachineDriverHistory::lockForUpdate()->findOrFail($history->id);
            $end = str_replace('T', ' ', $d['ended_at']).':00';
            $service->check(! $h->ended_at && $end > $h->started_at, 'Phân công đã kết thúc hoặc thời gian không hợp lệ.');
            $before = $h->toArray();
            $h->update(['ended_at' => $end]);
            DossierAudit::write('driver_assignment_closed', $h, $request->user()->id, $before);
        });

        return back()->with('success', 'Đã kết thúc riêng phân công này.');
    }

    public function drivers()
    {
        $snapshot = app(\App\Services\DossierZaloRoster::class)->snapshot();
        $links = app(\App\Services\DossierZaloRoster::class)->identities()
            ->filter(fn ($ids) => $ids->count() === 1);
        $names = ZaloMessage::whereIn('sender_id', $links->keys())->whereIn('id',
            ZaloMessage::selectRaw('max(id)')->whereIn('sender_id', $links->keys())->groupBy('sender_id'))->pluck('sender_name', 'sender_id');

        return view('dossiers.drivers', ['drivers' => Driver::orderBy('name')->orderBy('id')->paginate(20)->withQueryString(), 'links' => $links, 'names' => $names,
            'machines' => Machine::whereIn('id', $snapshot['rows']->pluck('machine_id'))->pluck('asset_code', 'id'),
            'roster' => $snapshot['rows']->keyBy('driver_id')]);
    }

    public function driver(Driver $driver, DossierReport $report)
    {
        $progress = $this->subjectProgress(null, [$driver->id]);
        if (request()->boolean('progress')) {
            return $this->progressResponse($progress);
        }
        $identity = app(\App\Services\Dossiers\Ai\DossierComparison::class)->identity($driver->id);
        $senderIds = app(\App\Services\DossierZaloRoster::class)->identities()->filter(fn ($ids) => $ids->count() === 1 && $ids->contains($driver->id))->keys();
        $senderNames = collect();
        $last = [];
        foreach (ZaloMessage::whereIn('sender_id', $senderIds)->whereNotNull('sender_name')->select(['sender_id', 'sender_name', 'received_at'])->orderBy('received_at')->orderBy('id')->cursor() as $message) {
            if (($last[$message->sender_id] ?? null) !== $message->sender_name) {
                $senderNames->push($message);
                $last[$message->sender_id] = $message->sender_name;
            }
        }

        return view('dossiers.driver', ['driver' => $driver, 'identity' => $identity, 'senderNames' => $senderNames->reverse()->values(), 'progress' => $progress, 'editTypes' => DossierType::orderBy('sort_order')->get(),
            'senderIds' => $senderIds, 'rows' => $report->subjectRows('driver', $driver->id, 'driver', null, $driver->name),
            'histories' => $driver->machineHistories()->with('machine')->latest('started_at')->get(),
            'records' => DossierRecord::with(['type', 'versions'])->where('driver_id', $driver->id)->get()]);
    }

    private function subjectProgress(?int $machine, array $drivers)
    {
        $sources = DossierSource::with(['machine', 'replacementVersion.record'])->withExists(['versions' => fn ($q) => $q->where('confirmed', true)])->where(function ($query) use ($machine, $drivers) {
            $query->where(fn ($q) => $q->where('document_target->subject', 'driver')->whereIn('document_target->id', $drivers));
            if ($machine) {
                $query->orWhere(fn ($q) => $q->where('document_target->subject', 'machine')->where('machine_id', $machine));
            }
            $query->orWhereHas('replacementVersion.record', function ($q) use ($machine, $drivers) {
                $q->whereIn('driver_id', $drivers)->when($machine, fn ($q) => $q->orWhere('machine_id', $machine));
            });
        })->latest('id')->limit(50)->get();

        return app(DossierProgress::class)->forSources($sources);
    }

    public function sender(Request $request, Driver $driver, DossierService $service)
    {
        $d = $request->validate(['sender_id' => ['required', 'string', 'max:100', 'exists:zalo_messages,sender_id']]);
        DB::transaction(function () use ($d, $driver, $request, $service) {
            ZaloMessage::where('sender_id', $d['sender_id'])->oldest('id')->lockForUpdate()->firstOrFail();
            Driver::lockForUpdate()->findOrFail($driver->id);
            $link = DossierSenderProfile::where('sender_id', $d['sender_id'])->first();
            $at = app(DossierStatus::class)->now()->format('Y-m-d H:i:s');
            $legacyConflict = \App\Models\ZaloSenderDriverLink::where('sender_id', $d['sender_id'])->where('driver_id', '!=', $driver->id)
                ->where('valid_from', '<=', $at)->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>', $at))->exists();
            $service->check(! $legacyConflict, 'Sender đang có liên kết tài xế OCR khác. Kiểm tra liên kết hiện có trước; hồ sơ không tự sửa mapping.');
            $service->check(! $link || $link->driver_id === $driver->id, 'Sender đã liên kết tài xế khác; không tự ghi đè.');
            app(\App\Services\DossierZaloRoster::class)->guardAssociation($d['sender_id'], $driver->id);
            if ($request->boolean('canonical')) {
                $active = \App\Models\ZaloSenderDriverLink::where('sender_id', $d['sender_id'])
                    ->where('driver_id', $driver->id)->whereNull('valid_to')->where('valid_from', '<=', $at)->exists();
                if (! $active) {
                    app(\App\Services\ZaloSenderDriverService::class)->link(['sender_id' => $d['sender_id'], 'driver_id' => $driver->id, 'valid_from' => $at], $request->user()->id);
                }

                return;
            }
            if (! $link) {
                $link = DossierSenderProfile::create([...$d, 'driver_id' => $driver->id, 'user_id' => $request->user()->id]);
                DossierAudit::write('sender_profile_linked', $link, $request->user()->id);
            }
        });

        return back()->with('success', 'Đã liên kết hồ sơ; không thay máy mặc định, phân công hay OCR.');
    }

    public function unlinkSender(Request $request, DossierSenderProfile $link)
    {
        $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        DB::transaction(function () use ($request, $link) {
            $before = $link->toArray();
            $before['reason'] = $request->input('reason');
            DossierAudit::write('sender_profile_unlinked', $link, $request->user()->id, $before);
            $link->delete();
        });

        return back();
    }

    public function types()
    {
        return view('dossiers.types', ['types' => DossierType::orderBy('sort_order')->get()]);
    }

    public function type(Request $request, ?DossierType $type = null)
    {
        $d = $request->validate(['name' => ['required', 'string', 'max:255'], 'groups' => ['required', 'array', 'min:1'],
            'groups.*' => ['in:machine,vehicle,driver'], 'required_groups' => ['nullable', 'array'], 'required_groups.*' => ['in:machine,vehicle,driver'],
            'expiry_mode' => ['required', 'in:expiring,permanent,review'], 'warning_days_text' => ['required', 'regex:/^\d+(\s*,\s*\d+)*$/'], 'sort_order' => ['required', 'integer', 'min:0', 'max:10000']]);
        $d['required_groups'] ??= [];
        app(DossierService::class)->check(! array_diff($d['required_groups'], $d['groups']), 'Nhóm bắt buộc phải thuộc nhóm áp dụng.');
        $d['warning_days'] = array_values(array_unique(array_map('intval', explode(',', $d['warning_days_text']))));
        app(DossierService::class)->check(max($d['warning_days']) <= 3660 && count($d['warning_days']) <= 20, 'Tối đa 20 mốc, mỗi mốc 0–3660 ngày.');
        unset($d['warning_days_text']);
        DB::transaction(function () use ($type, $d, $request) {
            $t = $type ?? new DossierType;
            $before = $t->exists ? $t->toArray() : [];
            $t->fill($d)->save();
            DossierAudit::write('type_saved', $t, $request->user()->id, $before);
        });

        return back()->with('success', 'Đã lưu danh mục.');
    }

    public function excel()
    {
        return Excel::download(new DossierWorkbook, 'Ho-so-'.app(DossierStatus::class)->now()->format('Y-m-d').'.xlsx');
    }

    public function export(Request $request, Machine $machine, DossierExportService $service)
    {
        $d = $request->validate(['driver_ids' => ['nullable', 'array'], 'driver_ids.*' => ['integer'], 'version_ids' => ['required', 'array'],
            'version_ids.*' => ['integer'], 'request_id' => ['required', 'uuid'], 'action' => ['required', 'in:preview,create']]);
        $drivers = array_map('intval', $d['driver_ids'] ?? []);
        $ids = array_map('intval', $d['version_ids']);
        if ($d['action'] === 'preview') {
            return view('dossiers.preview', ['machine' => $machine, 'preview' => $service->preview($machine, $drivers, $ids), 'requestId' => $d['request_id']]);
        }
        $e = $service->create($machine, $drivers, $ids, $d['request_id'], $request->boolean('acknowledged'), $request->user()->id);

        return redirect()->route('dossiers.export', $e);
    }

    public function print(Request $request, Machine $machine, DossierExportService $service)
    {
        $data = $request->validate(['request_id' => 'required|uuid']);
        $export = $service->create($machine, [], [], $data['request_id'], true, $request->user()->id, printCurrent: true);

        return redirect()->route('dossiers.export', $export);
    }

    public function showExport(Request $request, DossierExport $export)
    {
        abort_unless($export->user_id === $request->user()->id, 403);

        return view('dossiers.export', ['export' => $export]);
    }

    public function exportFile(Request $request, DossierExport $export)
    {
        abort_unless($export->user_id === $request->user()->id, 403);
        abort_unless($export->status === 'ready', 404);

        $date = \Carbon\CarbonImmutable::parse($export->getRawOriginal('created_at'), config('app.timezone'))
            ->timezone(config('dossiers.timezone'))->format('Y-m-d');

        if ($export->object_id) {
            return app(\App\Services\Dossiers\DossierObjects::class)->response(\App\Models\DossierObject::findOrFail($export->object_id), preg_replace('/[^A-Za-z0-9_-]/', '_', $export->machine->asset_code).'-'.$date.'.pdf', 'attachment');
        }

        return Storage::disk('dossiers')->download($export->path, preg_replace('/[^A-Za-z0-9_-]/', '_', $export->machine->asset_code).'-'.$date.'.pdf', ['Cache-Control' => 'private, no-store']);
    }

    public function retryExport(Request $request, DossierExport $export)
    {
        abort_unless($export->user_id === $request->user()->id, 403);
        DB::transaction(function () use ($export) {
            $e = DossierExport::lockForUpdate()->findOrFail($export->id);
            abort_if($e->status === 'ready', 422);
            $e->update(['status' => 'queued', 'error' => null]);
            app(\App\Services\Dossiers\DossierRemoteJobs::class)->export($e);
        });

        return back();
    }
}
