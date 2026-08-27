<?php

namespace App\Http\Controllers;

use App\Http\Requests\IndexDailyImageArchiveRequest;
use App\Models\CommandCenter;
use App\Models\Machine;
use App\Models\OcrJob;
use App\Services\DailyImageArchiveService;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DailyImageArchiveController extends Controller
{
    public function __construct(private readonly DailyImageArchiveService $service)
    {
    }

    public function index(IndexDailyImageArchiveRequest $request): View
    {
        $filters = $request->validated();
        $filters['month'] ??= now()->format('Y-m');

        return view('daily-images.index', [
            'groups' => $this->service->paginate($filters),
            'summary' => $this->service->summary($filters),
            'filters' => $filters,
            'machines' => Machine::query()
                ->whereIn('id', OcrJob::query()
                    ->select('machine_id')
                    ->where('document_type', 'DAILY_TIMEMARK')
                    ->whereIn('review_status', ['AUTO_APPROVED', 'APPROVED', 'CORRECTED'])
                    ->whereNotNull('machine_id'))
                ->orderBy('asset_code')
                ->get(['id', 'asset_code']),
            'commandCenters' => CommandCenter::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function export(IndexDailyImageArchiveRequest $request): BinaryFileResponse
    {
        $archive = $this->service->createZip($request->validated());
        return response()->download($archive['path'], $archive['name'])->deleteFileAfterSend(true);
    }
}
