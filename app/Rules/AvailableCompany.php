<?php

namespace App\Rules;

use App\Models\Company;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class AvailableCompany implements ValidationRule
{
    public function __construct(private readonly ?string $current = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!Company::query()->where('code', $value)
            ->where(fn ($q) => $q->where('is_active', true)->when($this->current !== null, fn ($q) => $q->orWhere('code', $this->current)))
            ->exists()) {
            $fail('Công ty không tồn tại hoặc đã ngừng sử dụng.');
        }
    }
}
