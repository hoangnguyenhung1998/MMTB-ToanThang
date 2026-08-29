<?php

namespace App\Services;

use App\Models\MachineHandoverCase;
use Throwable;

class MachineHandoverAlertDispatcher
{
    public function __construct(private readonly TelegramAlertClient $telegram) {}

    public function missingData(MachineHandoverCase $case): ?string
    {
        if ($case->missing_data_alerted_at) return null;
        $missing = collect($case->review_flags ?? [])->filter(fn ($flag) => str_starts_with($flag, 'MISSING_'))->implode(', ');
        return $this->send($case, [
            '⚠️ <b>BIÊN BẢN CẦN BỔ SUNG</b>',
            'Máy: <b>'.e($case->machine->asset_code).'</b>',
            'Thiếu: '.e($missing ?: 'thông tin bắt buộc'),
            route('machine-handovers.show', $case),
        ], 'missing_data_alerted_at');
    }

    public function waitingActivation(MachineHandoverCase $case): ?string
    {
        if ($case->ready_alerted_at) return null;
        return $this->send($case, [
            '✅ <b>ĐÃ BÀN GIAO — CHỜ KÍCH HOẠT</b>',
            'Máy: <b>'.e($case->machine->asset_code).'</b>',
            'Ngày: '.e($case->handover_date?->format('d/m/Y')),
            'Dự án: '.e($case->project?->name),
            'BCH: '.e($case->commandCenter?->name),
            route('machines.show', $case->machine),
        ], 'ready_alerted_at');
    }

    public function reminder(MachineHandoverCase $case): ?string
    {
        if ($case->reminder_alerted_at) return null;
        return $this->send($case, [
            '⏰ <b>MÁY VẪN CHỜ KÍCH HOẠT SAU 24 GIỜ</b>',
            'Máy: <b>'.e($case->machine->asset_code).'</b>',
            'Dự án/BCH: '.e($case->project?->name).' / '.e($case->commandCenter?->name),
            route('machines.show', $case->machine),
        ], 'reminder_alerted_at');
    }

    private function send(MachineHandoverCase $case, array $lines, string $timestamp): ?string
    {
        try {
            $this->telegram->send(implode("\n", $lines));
            $case->update([$timestamp => now(), 'last_error' => null]);
            return null;
        } catch (Throwable $exception) {
            report($exception); $case->update(['last_error' => 'Telegram: '.$exception->getMessage()]);
            return $exception->getMessage();
        }
    }
}
