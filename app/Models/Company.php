<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class Company extends Model
{
    protected $fillable = ['code', 'name', 'is_active'];
    protected $casts = ['is_active' => 'boolean'];

    protected static function booted(): void
    {
        static::updating(function (self $company) {
            if ($company->isDirty('code')) {
                throw ValidationException::withMessages(['code' => 'Mã công ty là định danh cố định. Anh có thể sửa tên hiển thị.']);
            }
        });
    }
}
