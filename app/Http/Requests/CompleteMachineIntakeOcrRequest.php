<?php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;
class CompleteMachineIntakeOcrRequest extends FormRequest
{
    public function rules(): array { return [
        'worker_id' => ['required','string','max:100'], 'confidence' => ['required','numeric','between:0,1'],
        'extraction' => ['required','array'], 'extraction.company' => ['nullable','string','max:255'],
        'extraction.chassis_no' => ['nullable','string','max:255'], 'extraction.engine_no' => ['nullable','string','max:255'],
        'extraction.machine_type' => ['nullable','string','max:255'], 'extraction.model_name' => ['nullable','string','max:255'],
        'extraction.brand' => ['nullable','string','max:255'], 'extraction.plate_no' => ['nullable','string','max:255'],
        'extraction.capacity_class' => ['nullable','integer'], 'extraction.vehicle_axles' => ['nullable','integer','in:2,3,4'],
        'extraction.manufacture_year' => ['nullable','integer','min:1900','max:'.(now()->year + 1)],
        'review_flags' => ['nullable','array'], 'review_flags.*' => ['string','max:100'], 'raw_text' => ['nullable','string'],
    ]; }
}
