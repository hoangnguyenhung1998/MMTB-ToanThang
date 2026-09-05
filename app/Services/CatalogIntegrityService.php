<?php

namespace App\Services;

use App\Models\CommandCenter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class CatalogIntegrityService
{
    public function assertUnused(Model $model): void
    {
        $isBch = $model instanceof CommandCenter;
        $column = $isBch ? 'command_center_id' : 'project_id';
        $references = [
            ...($isBch ? ['machine_assignment_bch_resolutions' => ['command_center_id']] : []),
            'machine_assignments' => [$column],
            'reconciliation_rows' => [$column],
            'machine_intake_cases' => [$column],
            'machine_handover_cases' => [$column],
            'machine_events' => $isBch ? ['from_command_center_id', 'to_command_center_id'] : ['project_id', 'from_project_id', 'to_project_id'],
        ];
        foreach ($references as $table => $columns) {
            foreach ($columns as $reference) {
                if (Schema::hasColumn($table, $reference) && DB::table($table)->where($reference, $model->getKey())->exists()) {
                    throw ValidationException::withMessages(['catalog' => 'Danh mục đang được sử dụng trong phân công, hồ sơ hoặc đối chiếu. Hãy sửa tên trên bản ghi hiện tại; không xóa và tạo lại.']);
                }
            }
        }
    }
}
