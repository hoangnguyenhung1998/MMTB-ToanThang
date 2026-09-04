<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreZaloAccountCommandRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'action' => ['required', Rule::in(['ZALO_ACCOUNT_SWITCH', 'ZALO_GROUPS_UPDATE'])],
            'account_id' => ['required', 'string', 'max:50', 'regex:/^[a-z0-9][a-z0-9_-]{0,49}$/'],
            'group_ids' => ['exclude_unless:action,ZALO_GROUPS_UPDATE', 'required_if:action,ZALO_GROUPS_UPDATE', 'array', 'min:1', 'max:200'],
            'group_ids.*' => ['required', 'string', 'max:64', 'distinct', 'regex:/^[A-Za-z0-9_-]{1,64}$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'group_ids.required_if' => 'Anh cần chọn ít nhất một nhóm để Collector lấy ảnh.',
            'group_ids.min' => 'Anh cần chọn ít nhất một nhóm để Collector lấy ảnh.',
        ];
    }
}
