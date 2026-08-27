<?php

namespace App\Services;

use App\Models\MachineIntakeCase;
use Throwable;

class MachineIntakeAlertDispatcher
{
    public function __construct(private readonly TelegramAlertClient $telegram) {}

    public function codeAssigned(MachineIntakeCase $case): ?string
    {
        try {
            $this->telegram->send(implode("\n", [
                '✅ <b>HỒ SƠ ĐÃ NHẬN MÃ</b>',
                'Hồ sơ: <b>'.e($case->reference).'</b>',
                'Số khung: <code>'.e($case->chassis_no).'</code>',
                'Mã máy: <b>'.e($case->asset_code).'</b>',
                'Nguồn: '.e($case->asset_code_source),
                'Trạng thái: <b>WAIT_HANDOVER</b>',
            ]));

            return null;
        } catch (Throwable $exception) {
            report($exception);
            return $exception->getMessage();
        }
    }
}
