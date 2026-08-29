<?php

namespace App\Console\Commands;

use App\Models\MachineHandoverCase;
use App\Services\MachineHandoverAlertDispatcher;
use Illuminate\Console\Command;

class DispatchMachineHandoverReminders extends Command
{
    protected $signature = 'machine-handovers:dispatch-reminders';
    protected $description = 'Nhắc một lần các máy đã bàn giao nhưng chưa kích hoạt sau 24 giờ';

    public function handle(MachineHandoverAlertDispatcher $alerts): int
    {
        $cases = MachineHandoverCase::query()->with(['machine', 'project', 'commandCenter'])
            ->where('status', 'HANDED_OVER')->whereNull('reminder_alerted_at')
            ->where('confirmed_at', '<=', now()->subDay())->get();
        $sent = 0;
        foreach ($cases as $case) {
            if ($case->machine->status !== 'HANDED_OVER') continue;
            if ($alerts->reminder($case) === null) $sent++;
        }
        $this->info("Dispatched {$sent} handover reminder(s).");
        return self::SUCCESS;
    }
}
