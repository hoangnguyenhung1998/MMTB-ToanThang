<?php

namespace App\Http\Controllers;

use App\Models\Machine;
use App\Services\MachineTimelineService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MachineTimelineController extends Controller
{
    public function index(
        Request $request,
        Machine $machine,
        MachineTimelineService $service
    ): View {
        $filters = $request->validate([
            'type' => ['nullable', 'in:system,status,handover,transfer,return,driver,document'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $timeline = $service->build($machine, $filters);

        $counts = [
            'all' => $service->build($machine)->count(),
            'operations' => $service->build($machine)
                ->whereIn('type', ['status', 'handover', 'transfer', 'return'])
                ->count(),
            'drivers' => $service->build($machine)->where('type', 'driver')->count(),
            'documents' => $service->build($machine)->where('type', 'document')->count(),
        ];

        return view('machines.timeline', compact(
            'machine',
            'timeline',
            'counts',
            'filters'
        ));
    }
}
