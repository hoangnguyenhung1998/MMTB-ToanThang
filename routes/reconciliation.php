<?php

use App\Http\Controllers\ReconciliationPeriodController;
use App\Http\Controllers\ReconciliationRowController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
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
    Route::post('/reconciliation-periods/{reconciliationPeriod}/start-review', [ReconciliationPeriodController::class, 'startReview'])
        ->name('reconciliation-periods.start-review');
    Route::post('/reconciliation-periods/{reconciliationPeriod}/confirm', [ReconciliationPeriodController::class, 'confirm'])
        ->name('reconciliation-periods.confirm');
    Route::post('/reconciliation-periods/{reconciliationPeriod}/lock', [ReconciliationPeriodController::class, 'lock'])
        ->name('reconciliation-periods.lock');
    Route::get('/reconciliation-periods/{reconciliationPeriod}/export', [ReconciliationPeriodController::class, 'export'])
        ->name('reconciliation-periods.export');
    Route::get('/reconciliation-periods/{reconciliationPeriod}/rows/{reconciliationRow}', [ReconciliationRowController::class, 'show'])
        ->name('reconciliation-rows.show');
    Route::put('/reconciliation-periods/{reconciliationPeriod}/rows/{reconciliationRow}', [ReconciliationRowController::class, 'update'])
        ->name('reconciliation-rows.update');
    Route::post('/reconciliation-periods/{reconciliationPeriod}/rows/{reconciliationRow}/review', [ReconciliationRowController::class, 'review'])
        ->name('reconciliation-rows.review');
    Route::post('/reconciliation-periods/{reconciliationPeriod}/rows/{reconciliationRow}/confirm', [ReconciliationRowController::class, 'confirm'])
        ->name('reconciliation-rows.confirm');
Route::post('/reconciliation-periods/{reconciliationPeriod}/delete', [ReconciliationPeriodController::class, 'destroy'])
    ->name('reconciliation-periods.delete');

Route::get('/reconciliation-periods/{reconciliationPeriod}/ocr-import', [\App\Http\Controllers\ReconciliationOcrImportController::class, 'create'])
    ->name('reconciliation-periods.ocr-import.create');

Route::post('/reconciliation-periods/{reconciliationPeriod}/ocr-import/preview', [\App\Http\Controllers\ReconciliationOcrImportController::class, 'preview'])
    ->name('reconciliation-periods.ocr-import.preview');

Route::post('/reconciliation-periods/{reconciliationPeriod}/ocr-import/confirm', [\App\Http\Controllers\ReconciliationOcrImportController::class, 'confirm'])
    ->name('reconciliation-periods.ocr-import.confirm');

Route::post('/reconciliation-periods/{reconciliationPeriod}/ocr-import/cancel', [\App\Http\Controllers\ReconciliationOcrImportController::class, 'cancel'])
    ->name('reconciliation-periods.ocr-import.cancel');

Route::get('/reconciliation-periods/{reconciliationPeriod}/machine-review', [\App\Http\Controllers\ReconciliationMachineReviewController::class, 'show'])
    ->name('reconciliation-periods.machine-review');

Route::post('/reconciliation-periods/{reconciliationPeriod}/machine-review/bulk-update', [\App\Http\Controllers\ReconciliationMachineReviewController::class, 'bulkUpdate'])
    ->name('reconciliation-periods.machine-review.bulk-update');

Route::post('/reconciliation-periods/{reconciliationPeriod}/machine-review/bulk-confirm', [\App\Http\Controllers\ReconciliationMachineReviewController::class, 'bulkConfirm'])
    ->name('reconciliation-periods.machine-review.bulk-confirm');
});
