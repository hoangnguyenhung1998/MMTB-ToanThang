<?php

namespace App\Http\Controllers;

use App\Services\OperationalIssueService;
use Illuminate\View\View;

class OperationCenterController extends Controller
{
    public function index(OperationalIssueService $service): View
    {
        return view('operation-center.index', $service->operationCenterData());
    }
}
