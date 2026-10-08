<?php

namespace App\Http\Controllers;

use App\Models\ReconciliationPeriod;
use App\Models\ReconciliationRepairRun;
use App\Services\Reconciliation\GlobalReconciliationRepairService;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ReconciliationRepairRunController extends Controller
{
    public function preview(Request $request, GlobalReconciliationRepairService $service): View
    {
        Gate::authorize('repairAll', ReconciliationPeriod::class);
        $preview = $service->preview($request->user());
        $token = Crypt::encryptString(json_encode($preview, JSON_THROW_ON_ERROR));

        return view('reconciliation.periods.repair-all', ['periods' => $preview['periods'], 'token' => $token, 'run' => null]);
    }

    public function store(Request $request, GlobalReconciliationRepairService $service): RedirectResponse
    {
        Gate::authorize('repairAll', ReconciliationPeriod::class);
        $data = $request->validate(['preview_token' => ['required', 'string', 'max:5000000']]);
        try {
            $preview = json_decode(Crypt::decryptString($data['preview_token']), true, 512, JSON_THROW_ON_ERROR);
            $run = $service->start($request->user(), $preview);
        } catch (DecryptException|\JsonException $exception) {
            abort(422, 'Preview không hợp lệ. Hãy xem trước lại.');
        } catch (\DomainException $exception) {
            abort($exception->getMessage() === 'GLOBAL_REPAIR_ALREADY_RUNNING' ? 409 : 422, $exception->getMessage());
        }

        return redirect()->route('reconciliation-periods.repair-all.show', $run);
    }

    public function show(Request $request, ReconciliationRepairRun $repairRun): View
    {
        Gate::authorize('repairAll', ReconciliationPeriod::class);
        abort_unless($repairRun->user_id === $request->user()->id, 403);

        return view('reconciliation.periods.repair-all', ['periods' => $repairRun->periods, 'run' => $repairRun, 'token' => null]);
    }
}
