<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreMachineIntakeEmailReplyRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'gmail_message_id' => ['required', 'string', 'max:255'],
            'gmail_thread_id' => ['nullable', 'string', 'max:255'],
            'sender' => ['nullable', 'string', 'max:500'],
            'subject' => ['nullable', 'string', 'max:1000'],
            'body_text' => ['nullable', 'string', 'max:100000'],
            'received_at' => ['nullable', 'date'],
            'candidate_asset_code' => ['nullable', 'string', 'max:100'],
            'confidence' => ['nullable', 'numeric', 'between:0,1'],
            'evidence_name' => ['nullable', 'string', 'max:255'],
            'evidence_mime' => ['nullable', 'string', 'max:100'],
            'evidence_base64' => ['nullable', 'string', 'max:15000000'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
