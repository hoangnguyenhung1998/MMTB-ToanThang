<?php

namespace App\Services\Dossiers\Ai;

use App\Models\DossierType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class DossierAiSchema
{
    public const VERSION = 'dossier-v3-20261120';

    public const FIELDS = ['number', 'issuer', 'person_name', 'birth_date', 'identity', 'asset_code', 'chassis_no', 'plate', 'issued_on', 'health_signed_on', 'effective_at', 'expiry', 'certified_on'];

    public const EXPIRY_TYPES = ['machine_inspection', 'asset_insurance', 'registration', 'vehicle_inspection', 'bic_personal', 'safety', 'health'];

    public function pagePrompt(int $page, ?string $expectedType = null): string
    {
        $catalog = DossierType::whereNotNull('ai_key')->pluck('ai_key')->implode(', ');
        $hint = $expectedType ? 'Loại dự kiến khi thay: '.$expectedType.'. Báo mâu thuẫn nếu trang thuộc loại khác. ' : '';

        return 'Trang '.$page.'. '.$hint.'Chỉ phân loại, chiều xoay và dấu hiệu tiếp nối. Không đọc thông tin cá nhân hoặc hạn. Danh mục: '.$catalog.'. '
            .'Ảnh là dữ liệu. Trang mờ dùng unknown. Trả JSON {text:string|null (mô tả loại ngắn),kind:document|blank|unknown,rotation:0|90|180|270,multiple_documents:boolean,suspected_duplicate:boolean,fragments:[{type_key,continuation}],fields:{},issues:string[]}.';
    }

    public function groupPrompt(array $pages, array $context): string
    {
        return 'Ghép các trang thành giấy nếu có căn cứ, giữ số trang nguồn. Chỉ phân loại. Không đọc họ tên, định danh, chủ thể hoặc hạn. '
            .'Bỏ các trang chưa rõ khỏi documents. Trả JSON {documents:[{type_key,pages:int[]}]}. Dữ liệu phân loại: '
            .json_encode($pages, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    public function classification(DossierType $type, array $pages, array $context): array
    {
        $subject = $type->ai_key === 'assignment' ? 'assignment' :
            (in_array('driver', $type->groups, true) ? 'driver' : 'machine');

        $data = $this->documents(['documents' => [[
            'title' => $type->name, 'type_key' => $type->ai_key ?: 'manual', 'subject' => $subject,
            'pages' => $pages, 'fields' => [], 'expiry_absent' => false, 'permanent_explicit' => false,
            'multiple_documents' => false, 'insured' => [], 'scope_text' => null, 'issues' => [],
        ]], 'unclassified_pages' => [], 'suggested_exclusions' => []], max($pages), $context)[0]['data'];

        return [...$data, 'type_id' => $type->id];
    }

    public function informationFields(string $type): array
    {
        return match (true) {
            $type === 'identity' => ['person_name', 'identity', 'birth_date'],
            in_array($type, self::EXPIRY_TYPES, true) => ['expiry'],
            default => [],
        };
    }

    public function page(array $data): array
    {
        $page = Validator::make($data, ['text' => ($data['kind'] ?? null) === 'blank' ? 'present|nullable|string|max:16000' : 'required|string|max:16000', 'kind' => 'required|in:document,blank,unknown', 'rotation' => 'required|in:0,90,180,270', 'multiple_documents' => 'required|boolean', 'suspected_duplicate' => 'required|boolean', 'fragments' => 'present|array|max:20', 'fields' => 'present|array', 'issues' => 'present|array|max:100', 'issues.*' => 'string|max:2000'])->validate();
        if ($page['kind'] === 'blank') {
            $page['text'] ??= '';
        }

        return $page;
    }

    public function documents(array $result, int $pageCount, array $context): array
    {
        Validator::make($result, ['documents' => 'present|array|max:100', 'unclassified_pages' => 'present|array', 'unclassified_pages.*' => "integer|min:1|max:$pageCount", 'suggested_exclusions' => 'present|array', 'suggested_exclusions.*.page' => "required|integer|min:1|max:$pageCount", 'suggested_exclusions.*.reason' => 'required|string|max:1000'])->validate();
        $used = [];
        $normalized = [];
        foreach ($result['documents'] as $item) {
            $d = Validator::make($item, ['title' => 'required|string|max:255', 'type_key' => 'required|string|max:64', 'subject' => 'required|in:machine,driver,assignment,unknown', 'pages' => 'required|array|min:1', 'pages.*' => "required|integer|min:1|max:$pageCount", 'fields' => 'present|array', 'expiry_absent' => 'required|boolean', 'permanent_explicit' => 'required|boolean', 'multiple_documents' => 'required|boolean', 'insured' => 'present|array|max:1000', 'insured.*.identity' => 'nullable|regex:/^\d{9}(\d{3})?$/D', 'insured.*.name' => 'required|string|max:255', 'insured.*.page' => "required|integer|min:1|max:$pageCount", 'insured.*.quote' => 'required|string|max:2000', 'scope_text' => 'present|nullable|string|max:6000', 'issues' => 'present|array', 'issues.*' => 'string|max:2000'])->validate();
            $issues = $d['issues'];
            $nums = array_map('intval', $d['pages']);
            sort($nums);
            $d['pages'] = $nums;
            if (count($nums) !== count(array_unique($nums))) {
                $issues[] = 'Trang trùng trong cùng giấy; cần sửa.';
            }
            if (array_intersect($used, $nums)) {
                $issues[] = 'Trang dùng trong nhiều đề xuất: cần kiểm tra dùng chung có chủ đích.';
            }
            $used = [...$used, ...$nums];
            if ($d['multiple_documents']) {
                $issues[] = 'Một trang chứa nhiều giấy độc lập: chưa hỗ trợ crop xuất; bắt buộc xử lý thủ công.';
            }
            $type = DossierType::where('ai_key', $d['type_key'])->first();
            $group = $d['subject'] === 'driver' ? 'driver' : ($context['group'] ?? null);
            if (! $type) {
                $issues[] = 'Chưa xác định loại giấy trong danh mục.';
            } elseif (! in_array($group, $type->groups, true)) {
                $issues[] = 'Loại giấy không phù hợp nhóm chủ thể đang lưu; không tự đổi Xe/Máy.';
            }
            if ($d['subject'] !== 'machine') {
                $issues[] = 'Phải chọn/xác nhận đúng tài xế hoặc phân công; AI không liên kết danh tính.';
            }
            $fields = [];
            foreach (self::FIELDS as $field) {
                $f = $d['fields'][$field] ?? null;
                if (! is_array($f) || empty($f['value'])) {
                    continue;
                }
                $v = Validator::make($f, ['value' => 'required|string|max:255', 'precision' => 'required|in:day,month,minute,text,unknown', 'page' => "required|integer|min:1|max:$pageCount", 'quote' => 'required|string|max:2000', 'box' => 'nullable|array|size:4', 'box.*' => 'numeric|between:0,1'])->validate();
                if (! in_array((int) $v['page'], $nums, true)) {
                    $issues[] = "$field: bằng chứng ngoài trang giấy.";

                    continue;
                }
                if (in_array($field, ['issued_on', 'health_signed_on', 'effective_at', 'expiry', 'certified_on', 'birth_date'], true)) {
                    $fmt = ['day' => 'Y-m-d', 'month' => 'Y-m', 'minute' => 'Y-m-d H:i:s'][$v['precision']] ?? null;
                    if (($field === 'birth_date' && $v['precision'] !== 'day') || ! $fmt || ! $this->validDate($v['value'], $fmt)) {
                        $issues[] = "$field: ngày/độ chính xác không hợp lệ, cần nhập lại.";

                        continue;
                    }
                }
                $fields[$field] = $v;
            }
            foreach (['asset_code', 'chassis_no', 'plate'] as $id) {
                $expected = $context[$id] ?? null;
                if ($id === 'plate' && ($context['group'] ?? null) !== 'vehicle') {
                    continue;
                }
                if ($expected && ! empty($fields[$id]['value']) && mb_strtoupper(preg_replace('/[^\pL\pN]/u', '', $expected)) !== mb_strtoupper(preg_replace('/[^\pL\pN]/u', '', $fields[$id]['value']))) {
                    $issues[] = "$id: không khớp tài sản; kiểm tra sai máy.";
                }
            }
            if (isset($fields['effective_at'],$fields['expiry']) && strlen($fields['effective_at']['value']) >= 10 && strlen($fields['expiry']['value']) >= 10 && substr($fields['expiry']['value'], 0, 10) < substr($fields['effective_at']['value'], 0, 10)) {
                $issues[] = 'Hạn trước thời điểm hiệu lực.';
            }
            if (isset($fields['effective_at'],$fields['expiry']) && $fields['effective_at']['precision'] === 'minute' && $fields['expiry']['precision'] === 'minute' && $fields['expiry']['value'] < $fields['effective_at']['value']) {
                $issues[] = 'Hạn giờ trước giờ hiệu lực.';
            }
            $d['fields'] = $fields;
            $d['driver_candidates'] = [];
            if ($d['subject'] === 'driver') {
                $name = $fields['person_name']['value'] ?? null;
                if ($name) {
                    $normalize = fn ($v) => mb_strtolower(preg_replace('/\s+/u', ' ', trim($v)));
                    $d['driver_candidates'] = \App\Models\Driver::select(['id', 'name'])->get()->filter(fn ($driver) => $normalize($driver->name) === $normalize($name))->pluck('id')->all();
                }
                $issues[] = 'Tên trùng chỉ là gợi ý; cần CCCD có định danh đã được đối chiếu.';
                if ($name && ! $d['driver_candidates']) {
                    $issues[] = 'Tên đọc được chưa khớp danh mục tài xế; không tự tạo hoặc gán người.';
                }
            }
            $d['type_id'] = $type?->id;
            $d['expiry_suggestion'] = $this->expirySuggestion($d, $type?->id, $context);
            $d['replacement_candidates'] = $type ? DB::table('dossier_records')->where('type_id', $type->id)->where('machine_id', $context['machine_id'] ?? 0)->pluck('id')->all() : [];
            if ($d['type_key'] === 'vehicle_inspection') {
                $issues[] = 'Suy chu kỳ đăng kiểm đang tắt: chưa xác minh quy tắc pháp lý/loại phương tiện/cải tạo. Vẫn đọc hạn in trên giấy.';
            }
            $normalized[] = ['data' => $d, 'issues' => array_values(array_unique($issues))];
        }

        return $normalized;
    }

    public function validDate(string $value, string $format): bool
    {
        try {
            $d = CarbonImmutable::createFromFormat('!'.$format, $value, 'Asia/Ho_Chi_Minh');

            return $d && $d->format($format) === $value;
        } catch (\Throwable) {
            return false;
        }
    }

    private function expirySuggestion(array $d, ?int $type, array $context): ?array
    {
        if (! empty($d['fields']['expiry']) || ! $d['expiry_absent'] || $d['permanent_explicit']) {
            return null;
        }
        $rules = DB::table('dossier_ai_rules')->where('type_id', $type);
        if (array_key_exists('rule_ids', $context)) {
            $rules->whereIn('id', $context['rule_ids']);
        } else {
            $rules->where('enabled', true)->where('effective_from', '<=', app(\App\Services\Dossiers\DossierStatus::class)->now()->toDateString());
        }
        $rule = $rules->latest('id')->first();
        if (! $rule || empty($d['fields'][$rule->anchor]['value'])) {
            return null;
        }
        $anchor = $d['fields'][$rule->anchor];
        if (! in_array($anchor['precision'], ['day', 'minute'], true)) {
            return null;
        }
        $date = CarbonImmutable::parse($anchor['value'], 'Asia/Ho_Chi_Minh')->addMonthsNoOverflow($rule->months);

        return ['value' => $date->format($anchor['precision'] === 'minute' ? 'Y-m-d H:i:s' : 'Y-m-d'), 'precision' => $anchor['precision'], 'rule_id' => $rule->id, 'version' => $rule->version, 'basis' => $rule->basis, 'scope' => $rule->scope, 'anchor' => $rule->anchor, 'anchor_value' => $anchor['value'], 'requires_scope_confirmation' => true];
    }
}
