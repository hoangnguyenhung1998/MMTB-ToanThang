<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Machine;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ActivityController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'event' => ['nullable', 'string', 'max:80'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'machine_id' => ['nullable', 'integer', 'exists:machines,id'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        $activities = ActivityLog::query()
            ->with(['user:id,name,email', 'machine:id,asset_code,chassis_no,plate_no'])
            ->filters($filters)
            ->latest('occurred_at')
            ->paginate(30)
            ->withQueryString();

        return view('activities.index', [
            'activities' => $activities,
            'filters' => $filters,
            'users' => User::query()->orderBy('name')->get(['id', 'name']),
            'machines' => Machine::query()->orderBy('asset_code')->get(['id', 'asset_code']),
            'eventOptions' => ActivityLog::query()
                ->select('event')
                ->distinct()
                ->orderBy('event')
                ->pluck('event'),
        ]);
    }
}
