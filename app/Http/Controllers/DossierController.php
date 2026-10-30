<?php

namespace App\Http\Controllers;

use App\Exports\DossierWorkbook;
use App\Jobs\BuildDossierExport;
use App\Jobs\ProcessDossierSource;
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
        $machines = Machine::orderBy('asset_code')->when($request->filled('q'), fn ($q) => $q->where('asset_code', 'like', '%'.$request->string('q').'%'))->get();
        $report->warm($machines);
        $items = $machines->map(function ($machine) use ($report) {
            $rows = $report->rows($machine);

            return ['machine' => $machine, 'drivers' => $report->drivers($machine), 'assignments' => $report->assignments($machine), 'counts' => $report->summary($rows)];
        });
        $totals = [];
        foreach (array_keys(DossierStatus::LABELS) as $s) {
            $totals[$s] = $items->sum(fn ($i) => $i['counts'][$s] ?? 0);
        }
        if ($request->filled('status')) {
            $items = $items->filter(fn ($i) => ($i['counts'][$request->string('status')->toString()] ?? 0) > 0);
        }

        return view('dossiers.index', ['items' => $items, 'totals' => $totals, 'labels' => DossierStatus::LABELS,
            'batches' => DossierBatch::latest()->limit(20)->get()]);
    }

    public function batch(Request $request)
    {
        $batch = DossierBatch::create(['id' => (string) Str::uuid(), 'user_id' => $request->user()->id]);

        return redirect()->route('dossiers.batch', $batch);
    }

    public function showBatch(DossierBatch $batch)
    {
        return view('dossiers.batch', ['batch' => $batch, 'sources' => DossierSource::with('machine')->where('batch_id', $batch->id)->get()]);
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
            report($e);

            return response()->json(['message' => 'Không lưu được file. Các file khác trong đợt vẫn được giữ; thử lại riêng file này.'], 422);
        }
    }

    public function source(DossierSource $source)
    {
        $replacement = request()->filled('replace') ? DossierVersion::with('record')->where('source_id', $source->id)->where('confirmed', true)->findOrFail(request('replace')) : null;

        return view('dossiers.source', ['source' => $source, 'machines' => Machine::orderBy('asset_code')->get(),
            'aiAvailable' => \Illuminate\Support\Facades\Schema::hasTable('dossier_ai_runs'),
            'aiRun' => \Illuminate\Support\Facades\Schema::hasTable('dossier_ai_runs') ? \App\Models\DossierAiRun::with(['pages', 'proposals'])->where('source_id', $source->id)->latest('id')->first() : null,
            'replacement' => $replacement,
            'drivers' => Driver::orderBy('name')->get(), 'types' => DossierType::orderBy('sort_order')->get(),
            'assignments' => $source->machine?->assignments()->with(['project', 'commandCenter'])->get() ?? collect(),
            'versions' => DossierVersion::with(['record.type', 'record.driver', 'record.assignment'])->where('source_id', $source->id)->get()]);
    }

    public function saveSource(Request $request, DossierSource $source, DossierService $service)
    {
        $service->saveSource($source, [...$request->all(), 'excluded_pages' => $request->input('excluded_pages', []), 'confirm' => $request->boolean('confirm')], $request->user()->id);

        return back()->with('success', 'Đã lưu đối chiếu / phân loại nguồn.');
    }

    public function retrySource(Request $request, DossierSource $source)
    {
        DB::transaction(function () use ($source, $request) {
            $s = DossierSource::lockForUpdate()->findOrFail($source->id);
            abort_if($s->status === 'ready', 422);
            $before = $s->toArray();
            $s->update(['status' => 'queued', 'error' => null]);
            ProcessDossierSource::dispatch($s->id)->afterCommit();
            DossierAudit::write('source_retry', $s, $request->user()->id, $before);
        });

        return back()->with('success', 'Đã xếp hàng tiếp tục từ trang lưu gần nhất.');
    }

    public function file(DossierSource $source, string $kind, ?int $page = null)
    {
        $path = match ($kind) {
            'source' => $source->path, 'processed' => $source->processed_path, default => "thumbnails/{$source->id}/{$page}.png"
        };
        abort_unless(in_array($kind, ['source', 'processed', 'thumbnail'], true) && $path, 404);
        if ($kind === 'thumbnail') {
            abort_unless($page && $page > 0 && $page <= $source->progress, 404);
        }
        abort_unless(Storage::disk('dossiers')->exists($path), 404);

        return response()->file(Storage::disk('dossiers')->path($path), ['Content-Type' => $kind !== 'thumbnail' ? 'application/pdf' : 'image/png',
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => 'sandbox']);
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

    public function archive(Request $request, DossierVersion $version, DossierService $service)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000'], 'archived' => ['required', 'boolean']]);
        $service->archive($version, (bool) $data['archived'], $data['reason'], $request->user()->id);

        return back()->with('success', 'Đã ghi lịch sử lưu trữ / khôi phục.');
    }

    public function machine(Machine $machine, DossierReport $report)
    {
        return view('dossiers.machine', ['machine' => $machine, 'rows' => $report->rows($machine), 'drivers' => $report->drivers($machine),
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

    public function driver(Driver $driver, DossierReport $report)
    {
        return view('dossiers.driver', ['driver' => $driver, 'rows' => $report->subjectRows('driver', $driver->id, 'driver', null, $driver->name),
            'links' => DossierSenderProfile::where('driver_id', $driver->id)->get(),
            'senders' => ZaloMessage::select('sender_id')->whereNotNull('sender_id')->distinct()->orderBy('sender_id')->get(),
            'histories' => $driver->machineHistories()->with('machine')->latest('started_at')->get(),
            'records' => DossierRecord::with(['type', 'versions'])->where('driver_id', $driver->id)->get()]);
    }

    public function sender(Request $request, Driver $driver, DossierService $service)
    {
        $d = $request->validate(['sender_id' => ['required', 'string', 'max:100', 'exists:zalo_messages,sender_id']]);
        DB::transaction(function () use ($d, $driver, $request, $service) {
            ZaloMessage::where('sender_id', $d['sender_id'])->oldest('id')->lockForUpdate()->firstOrFail();
            $link = DossierSenderProfile::where('sender_id', $d['sender_id'])->first();
            $at = app(DossierStatus::class)->now()->format('Y-m-d H:i:s');
            $legacyConflict = \App\Models\ZaloSenderDriverLink::where('sender_id', $d['sender_id'])->where('driver_id', '!=', $driver->id)
                ->where('valid_from', '<=', $at)->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>', $at))->exists();
            $service->check(! $legacyConflict, 'Sender đang có liên kết tài xế OCR khác. Kiểm tra liên kết hiện có trước; hồ sơ không tự sửa mapping.');
            $service->check(! $link || $link->driver_id === $driver->id, 'Sender đã liên kết tài xế khác; không tự ghi đè.');
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

    public function showExport(Request $request, DossierExport $export)
    {
        abort_unless($export->user_id === $request->user()->id, 403);

        return view('dossiers.export', ['export' => $export]);
    }

    public function exportFile(Request $request, DossierExport $export)
    {
        abort_unless($export->user_id === $request->user()->id, 403);
        abort_unless($export->status === 'ready' && Storage::disk('dossiers')->exists($export->path), 404);

        $date = \Carbon\CarbonImmutable::parse($export->getRawOriginal('created_at'), config('app.timezone'))
            ->timezone(config('dossiers.timezone'))->format('Y-m-d');

        return Storage::disk('dossiers')->download($export->path, preg_replace('/[^A-Za-z0-9_-]/', '_', $export->machine->asset_code).'-'.$date.'.pdf', ['Cache-Control' => 'private, no-store']);
    }

    public function retryExport(Request $request, DossierExport $export)
    {
        abort_unless($export->user_id === $request->user()->id, 403);
        DB::transaction(function () use ($export) {
            $e = DossierExport::lockForUpdate()->findOrFail($export->id);
            abort_if($e->status === 'ready', 422);
            $e->update(['status' => 'queued', 'error' => null]);
            BuildDossierExport::dispatch($e->id)->afterCommit();
        });

        return back();
    }
}
