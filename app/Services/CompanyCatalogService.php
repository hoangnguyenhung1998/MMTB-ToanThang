<?php

namespace App\Services;

use App\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CompanyCatalogService
{
    public function save(?Company $company, array $data): Company
    {
        return DB::transaction(function () use ($company, $data) {
            if ($company) {
                $company = Company::query()->lockForUpdate()->findOrFail($company->id);
                unset($data['code']);
                $company->update($data);
            } else {
                $company = Company::create($data);
            }
            return $company;
        });
    }

    public function delete(Company $company): void
    {
        DB::transaction(function () use ($company) {
            $company = Company::query()->lockForUpdate()->findOrFail($company->id);
            foreach (['machines', 'machine_intake_cases'] as $table) {
                if (DB::table($table)->where('company', $company->code)->exists()) {
                    throw ValidationException::withMessages(['company' => 'Công ty đã được sử dụng. Hãy bỏ chọn Đang sử dụng thay vì xóa.']);
                }
            }
            $company->delete();
        });
    }
}
