<?php

use App\Http\Controllers\ReconciliationPeriodController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])->group(function () {
    Route::get('/reconciliation-periods', [ReconciliationPeriodController::class, 'index'])
        ->name('reconciliation-periods.index');
    Route::get('/reconciliation-periods/create', [ReconciliationPeriodController::class, 'create'])
        ->name('reconciliation-periods.create');
    Route::post('/reconciliation-periods', [ReconciliationPeriodController::class, 'store'])
        ->name('reconciliation-periods.store');
    Route::get('/reconciliation-periods/{reconciliationPeriod}', [ReconciliationPeriodController::class, 'show'])
        ->name('reconciliation-periods.show');
    Route::post('/reconciliation-periods/{reconciliationPeriod}/generate', [ReconciliationPeriodController::class, 'generate'])
        ->name('reconciliation-periods.generate');
});
