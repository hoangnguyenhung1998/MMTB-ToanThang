<?php

namespace App\Http\Controllers;

use App\Services\OcrMonitoringService;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

class OcrMonitoringController extends Controller
{
    public function __construct(private readonly OcrMonitoringService $monitoring)
    {
    }

    public function index(): View
    {
        return view('ocr-monitoring.index', $this->monitoring->dashboardData());
    }

    public function status(): JsonResponse
    {
        return response()->json($this->monitoring->dashboardData());
    }
}
