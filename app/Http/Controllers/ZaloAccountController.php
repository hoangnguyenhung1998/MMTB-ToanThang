<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreZaloAccountCommandRequest;
use App\Models\AutomationOperationalCommand;
use App\Services\AutomationHealthService;
use App\Services\AutomationOperationalCommandService;
use App\Services\ZaloAccountManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class ZaloAccountController extends Controller
{
    public function index(ZaloAccountManagementService $management, AutomationHealthService $health): View
    {
        $collector = $management->collector();
        if ($collector) {
            $collector->effective_status = $health->statusFor($collector);
        }

        return view('zalo-accounts.index', [
            'collector' => $collector,
            'accounts' => data_get($collector?->metrics, 'zalo_accounts', []),
            'commands' => $collector?->commands()
                ->whereIn('action', ['ZALO_ACCOUNT_SWITCH', 'ZALO_GROUPS_UPDATE'])
                ->latest()->limit(15)->get() ?? collect(),
        ]);
    }

    public function store(
        StoreZaloAccountCommandRequest $request,
        ZaloAccountManagementService $management,
        AutomationOperationalCommandService $commands,
    ): JsonResponse|RedirectResponse {
        $collector = $management->collector();
        abort_unless($collector, 503, 'Zalo Collector chưa gửi heartbeat.');
        $data = $request->validated();
        $payload = ['account_id' => $data['account_id']];
        if ($data['action'] === 'ZALO_GROUPS_UPDATE') {
            $payload['group_ids'] = array_values($data['group_ids']);
        }
        $command = $commands->create($collector, (int) $request->user()->id, $data['action'], $payload);

        if ($request->expectsJson()) {
            return response()->json([
                'command_id' => $command->id,
                'status_url' => route('zalo-accounts.commands.status', $command),
                'message' => 'Đã gửi lệnh tới laptop.',
            ], 202);
        }
        return back()->with('success', 'Đã gửi lệnh tới laptop; trạng thái sẽ cập nhật trong tối đa 60 giây.');
    }

    public function status(
        AutomationOperationalCommand $command,
        ZaloAccountManagementService $management,
    ): JsonResponse {
        return response()->json(['command' => $management->commandStatus($command)]);
    }
}
