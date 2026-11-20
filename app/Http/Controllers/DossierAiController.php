<?php

namespace App\Http\Controllers;

use App\Models\DossierAiProposal;
use App\Models\DossierAiRun;
use App\Models\DossierSource;
use App\Models\DossierType;
use App\Services\Dossiers\Ai\DossierAiService;
use App\Services\Dossiers\DossierAudit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class DossierAiController extends Controller
{
    public function request(Request $request, DossierSource $source, DossierAiService $service)
    {
        $run = $service->request($source, $request->user()->id);

        if ($run->paused) {
            return redirect()->route('dossiers.source', $source)->with('success', 'AI đang dừng xếp tiếp; kiểm tra giới hạn pilot rồi chủ động bấm Xếp tiếp trên hồ sơ.');
        }

        return redirect()->route('dossiers.source', $source)->with('success', $run->status === 'unconfigured' ? 'Chưa cấu hình AI. Nhập tay vẫn dùng được; cấu hình rồi bấm Phân tích AI.' : 'Đã xếp hàng AI riêng cho hồ sơ; chưa xác nhận giấy.');
    }

    public function control(Request $request, DossierAiRun $run, DossierAiService $service)
    {
        $d = $request->validate(['action' => 'required|in:pause,resume,retry', 'page' => 'nullable|integer|min:1']);
        $service->control($run, $d['action'], isset($d['page']) ? (int) $d['page'] : null, $request->user()->id);

        return back()->with('success', 'Đã cập nhật tác vụ AI.');
    }

    public function compare(Request $request, DossierAiProposal $proposal, \App\Services\Dossiers\Ai\DossierComparison $compare)
    {
        $data = $request->validate(['subject' => 'required|in:driver,machine,assignment', 'subject_id' => 'required|integer|min:1', 'type_id' => 'required|integer|min:1']);
        $source = $proposal->run->source;
        $id = (int) $data['subject_id'];
        $subject = $data['subject'];
        abort_unless(match ($subject) {
            'driver' => \App\Models\Driver::whereKey($id)->exists(),
            'machine' => $source->machine_id === $id,
            'assignment' => $source->machine?->assignments()->whereKey($id)->exists(),
        }, 422);
        $result = $compare->compare($proposal->data, $source, $subject, $id);
        $labels = ['match' => 'Khớp', 'mismatch' => 'Không khớp', 'missing' => 'Chưa đủ dữ liệu'];
        foreach ($result['fields'] as $field => &$row) {
            $row['label'] = $labels[$row['state']];
            if ($field === 'identity') {
                foreach (['ocr', 'web'] as $side) {
                    $row[$side] = $row[$side] ? '••••'.substr($row[$side], -4) : null;
                }
            }
        }
        unset($row);
        $records = \App\Models\DossierRecord::with('versions')->where($subject.'_id', $id)->where('type_id', $data['type_id'])->get()
            ->map(function ($record) {
                $current = app(\App\Services\Dossiers\DossierStatus::class)->current($record);

                return $current && ! $current->archived ? ['id' => $record->id, 'instance' => $record->instance, 'version' => $current->id] : null;
            })->filter()->values();

        $issues = array_filter($proposal->issues ?? [], fn ($issue) => ! str_starts_with($issue, 'Suy chu kỳ đăng kiểm') &&
            ! str_starts_with($issue, 'Phải chọn/xác nhận') && ! str_starts_with($issue, 'Tên trùng chỉ'));

        return response()->json(['bulk_match' => ! $issues && $result['status'] === 'match' && (int) $data['type_id'] === $proposal->data['type_id'], 'label' => $labels[$result['status']], 'fields' => $result['fields'], 'records' => $records], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function label(Request $request, DossierAiRun $run, int $page, DossierAiService $service)
    {
        $data = $request->validate(['kind' => 'required|in:blank,document,unknown']);
        $service->label($run, $page, $data['kind'], $request->user()->id);

        return back()->with('success', 'Đã đổi nhãn và cập nhật đề xuất nhóm giấy.');
    }

    public function review(Request $request, DossierAiProposal $proposal, DossierAiService $service)
    {
        $v = $service->review($proposal, $this->payload($request->all()), $request->user()->id);

        return redirect()->route('dossiers.source', $v->source_id)->with('success', 'Đã lưu giấy #'.$v->id.' qua service hồ sơ; bản cũ được giữ.');
    }

    public function bulk(Request $request, DossierAiRun $run, DossierAiService $service)
    {
        $d = $request->validate(['items' => 'required|array|min:1|max:50', 'items.*.id' => 'required|integer', 'items.*.data' => 'required|array']);
        $outcome = DB::transaction(function () use ($d, $run, $service, $request) {
            $source = DossierSource::lockForUpdate()->findOrFail($run->source_id);
            $base = $source->revision;
            $saved = [];
            $skipped = [];
            foreach ($d['items'] as $item) {
                $proposal = $run->proposals()->where('generation', $run->generation)->findOrFail($item['id']);
                abort_unless((int) ($item['data']['source_revision'] ?? -1) === $base && $base === $run->source_revision, 422, 'Hồ sơ vừa đổi; tải lại trước khi duyệt nhiều mục.');
                if ($proposal->version_id) {
                    $skipped[] = $proposal->id;

                    continue;
                }
                $payload = $this->payload($item['data']);
                $data = $proposal->data;
                $comparison = app(\App\Services\Dossiers\Ai\DossierComparison::class)->compare($data, $source, $payload['subject'] ?? null, (int) ($payload['subject_id'] ?? 0));
                $expiry = $data['fields']['expiry'] ?? null;
                $knownExpiry = (! in_array($data['type_key'], \App\Services\Dossiers\Ai\DossierAiSchema::EXPIRY_TYPES, true) && ($payload['expiry_precision'] ?? null) === 'not_tracked') || ($expiry && $expiry['value'] === ($payload['expiry_value'] ?? null) &&
                    $expiry['precision'] === ($payload['expiry_precision'] ?? null)) ||
                    ($data['permanent_explicit'] && ($payload['expiry_precision'] ?? '') === 'permanent') ||
                    (! empty($data['expiry_suggestion']) && $payload['use_rule'] && $payload['rule_scope_confirmed']);
                $issues = array_filter($proposal->issues ?? [], fn ($issue) => ! str_starts_with($issue, 'Suy chu kỳ đăng kiểm') &&
                    ! str_starts_with($issue, 'Phải chọn/xác nhận') && ! str_starts_with($issue, 'Tên trùng chỉ'));
                if ($comparison['status'] !== 'match' || $issues || ! $knownExpiry || ! $payload['reviewed_evidence'] ||
                    ($payload['subject'] ?? null) !== $data['subject'] ||
                    ($data['subject'] === 'machine' && (int) ($payload['subject_id'] ?? 0) !== $source->machine_id) ||
                    ($data['subject'] === 'assignment' && ! $source->machine?->assignments()->whereKey((int) ($payload['subject_id'] ?? 0))->exists()) ||
                    (int) ($payload['type_id'] ?? 0) !== $data['type_id'] ||
                    array_map('intval', array_column($payload['pages'], 'page')) !== $data['pages']) {
                    $skipped[] = $proposal->id;

                    continue;
                }
                $payload['source_revision'] = $source->fresh()->revision;
                $payload['acknowledge_stale'] = true;
                $payload['confirmed'] = true;
                try {
                    $saved[] = $service->review($proposal, $payload, $request->user()->id)->id;
                } catch (\Illuminate\Validation\ValidationException) {
                    $skipped[] = $proposal->id;
                }
            }

            return ['saved' => $saved, 'skipped' => $skipped];
        }, 3);

        return response()->json($outcome, $outcome['saved'] ? 200 : 422);
    }

    private function payload(array $data): array
    {
        $nums = preg_split('/[\s,;]+/', trim($data['page_numbers'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
        $data['pages'] = array_map(fn ($p) => ['page' => $p, 'rotation' => (int) ($data['rotations'][$p] ?? 0)], $nums);
        $data['effective_at'] = str_replace('T', ' ', $data['effective_at'] ?? '');
        if (strlen($data['effective_at']) === 16) {
            $data['effective_at'] .= ':00';
        }
        foreach (['confirmed', 'shared_pages', 'reviewed_evidence', 'type_checked', 'verify_identity', 'acknowledge_stale', 'use_rule', 'rule_scope_confirmed'] as $k) {
            $data[$k] = filter_var($data[$k] ?? false, FILTER_VALIDATE_BOOLEAN);
        }

        return $data;
    }

    public function image(DossierAiRun $run, int $page)
    {
        $p = $run->pages()->where('page', $page)->firstOrFail();
        if ($p->image_object_id) {
            return app(\App\Services\Dossiers\DossierObjects::class)->response(\App\Models\DossierObject::findOrFail($p->image_object_id), 'page.jpg');
        }

        return Storage::disk('dossiers')->response("ai-images/{$run->source->sha256}/{$page}-{$p->dpi}.jpg", 'page.jpg', ['Cache-Control' => 'private, no-store', 'Content-Security-Policy' => 'sandbox', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function rules()
    {
        return view('dossiers.ai-rules', ['types' => DossierType::orderBy('sort_order')->get(), 'rules' => DB::table('dossier_ai_rules')->join('dossier_types', 'dossier_types.id', '=', 'dossier_ai_rules.type_id')->select('dossier_ai_rules.*', 'dossier_types.name')->orderByDesc('dossier_ai_rules.id')->get()]);
    }

    public function saveRule(Request $request)
    {
        $d = $request->validate(['type_id' => 'required|integer|exists:dossier_types,id', 'months' => 'required|integer|min:1|max:120', 'anchor' => 'required|in:issued_on,health_signed_on,effective_at', 'scope' => 'required|string|max:2000', 'basis' => 'required|string|max:2000', 'effective_from' => 'required|date_format:Y-m-d']);
        abort_if(in_array(DossierType::findOrFail($d['type_id'])->ai_key, ['registration', 'vehicle_inspection'], true), 422, 'Suy chu kỳ đăng kiểm/đăng ký đang tắt, chưa xác minh pháp lý.');
        DB::transaction(function () use ($d, $request) {
            DossierType::whereKey($d['type_id'])->lockForUpdate()->firstOrFail();
            DB::table('dossier_ai_rules')->where('type_id', $d['type_id'])->update(['enabled' => false]);
            $id = DB::table('dossier_ai_rules')->insertGetId([...$d, 'version' => (string) \Illuminate\Support\Str::uuid(), 'enabled' => $request->boolean('enabled'), 'created_at' => now(), 'updated_at' => now()]);
            DossierAudit::write('ai_rule_version_created', DossierType::findOrFail($d['type_id']), $request->user()->id, ['rule_id' => $id]);
        });

        return back()->with('success', 'Đã lưu phiên bản quy tắc; hồ sơ đã duyệt giữ căn cứ cũ.');
    }
}
