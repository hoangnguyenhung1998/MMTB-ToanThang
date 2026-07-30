// Trong app/Http/Controllers/MachineController.php,
// thay toàn bộ method show() hiện tại bằng method dưới đây.

public function show(Machine $machine): View
{
    $currentInfo = $this->machineService->getCurrentInfo($machine->id);
    $history = $this->machineService->getHistory($machine->id);

    $proofEvents = MachineEvent::with([
            'fromProject',
            'toProject',
            'fromCommandCenter',
            'toCommandCenter',
        ])
        ->where('machine_id', $machine->id)
        ->whereIn('type', ['HANDOVER', 'TRANSFER', 'RETURN'])
        ->orderByDesc('occurred_at')
        ->get();

    $timeline = app(\App\Services\MachineTimelineService::class)
        ->build($machine);

    return view('machines.show', [
        'machine' => $machine,
        'currentInfo' => $currentInfo,
        'assignments' => $history['assignments'],
        'driverHistory' => $history['driver_history'],
        'events' => $history['events'],
        'proofEvents' => $proofEvents,
        'timeline' => $timeline,
    ]);
}
