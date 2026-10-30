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

    public function review(Request $request, DossierAiProposal $proposal, DossierAiService $service)
    {
        $v = $service->review($proposal, $this->payload($request->all()), $request->user()->id);

        return redirect()->route('dossiers.source', $v->source_id)->with('success', 'Đã lưu giấy #'.$v->id.' qua service hồ sơ; bản cũ được giữ.');
    }

    public function bulk(Request $request, DossierAiRun $run, DossierAiService $service)
    {
        $d = $request->validate(['items' => 'required|array|min:1|max:50', 'items.*.id' => 'required|integer', 'items.*.data' => 'required|array']);
        DB::transaction(function () use ($d, $run, $service, $request) {
            $s = DossierSource::lockForUpdate()->findOrFail($run->source_id);
            $base = $s->revision;
            foreach ($d['items'] as $item) {
                $p = DossierAiProposal::where('run_id', $run->id)->findOrFail($item['id']);
                abort_unless((int) ($item['data']['source_revision'] ?? -1) === $base, 422, 'Hồ sơ vừa đổi; tải lại trước khi duyệt nhiều mục.');
                $payload = $this->payload($item['data']);
                $payload['source_revision'] = $s->fresh()->revision;
                if ($base === $run->source_revision) {
                    $payload['acknowledge_stale'] = true;
                }
                // Human explicitly checked every selected form in this request.
                $service->review($p, $payload, $request->user()->id);
            }
        }, 3);

        return response()->json(['ok' => true]);
    }

    private function payload(array $data): array
    {
        $nums = preg_split('/[\s,;]+/', trim($data['page_numbers'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
        $data['pages'] = array_map(fn ($p) => ['page' => $p, 'rotation' => (int) ($data['rotations'][$p] ?? 0)], $nums);
        $data['effective_at'] = str_replace('T', ' ', $data['effective_at'] ?? '');
        if (strlen($data['effective_at']) === 16) {
            $data['effective_at'] .= ':00';
        }
        foreach (['confirmed', 'shared_pages', 'reviewed_evidence', 'acknowledge_stale', 'use_rule', 'rule_scope_confirmed'] as $k) {
            $data[$k] = filter_var($data[$k] ?? false, FILTER_VALIDATE_BOOLEAN);
        }

        return $data;
    }

    public function image(DossierAiRun $run, int $page)
    {
        $p = $run->pages()->where('page', $page)->firstOrFail();
        $relative = "ai-images/{$run->source->sha256}/{$page}-{$p->dpi}.jpg";
        abort_unless(Storage::disk('dossiers')->exists($relative), 404);

        return response()->file(Storage::disk('dossiers')->path($relative), ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => 'sandbox']);
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
