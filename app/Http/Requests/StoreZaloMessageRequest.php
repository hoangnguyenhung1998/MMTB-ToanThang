<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreZaloMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'group_id' => ['required', 'string', 'max:100'],
            'message_id' => ['required', 'string', 'max:100'],
            'sender_id' => ['nullable', 'string', 'max:100'],
            'sender_name' => ['nullable', 'string', 'max:255'],
            'sent_at' => ['required', 'date'],
            'attachment_index' => ['required', 'integer', 'min:0', 'max:100'],
            'sha256' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'raw_payload' => ['nullable', 'json'],
            'file' => [
                'required',
                'file',
                'max:'.config('collector.max_file_kilobytes'),
                'mimetypes:image/jpeg,image/png,image/webp,image/gif,image/heic,image/heif,application/octet-stream',
            ],
        ];
    }
}
