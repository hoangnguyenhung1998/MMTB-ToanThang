<?php

namespace App\Services;

use App\Models\MachineIntakeCase;
use App\Models\MachineIntakeEmailReply;
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

    public function codeCandidate(MachineIntakeEmailReply $reply): ?string
    {
        try {
            $this->telegram->send(implode("\n", [
                '📩 <b>GMAIL ĐÃ NHẬN MÃ ĐỀ XUẤT</b>',
                'Hồ sơ: <b>'.e($reply->intakeCase?->reference).'</b>',
                'Số khung: <code>'.e($reply->intakeCase?->chassis_no).'</code>',
                'Mã đề xuất: <b>'.e($reply->candidate_asset_code).'</b>',
                'Độ tin cậy: '.number_format(((float) $reply->confidence) * 100)."%",
                'Nguồn: '.e($reply->sender),
                '⚠️ Chưa tạo máy — cần xác nhận trên web.',
                route('machine-intakes.show', $reply->intakeCase),
            ]));
            return null;
        } catch (Throwable $exception) {
            report($exception);
            return $exception->getMessage();
        }
    }
}
