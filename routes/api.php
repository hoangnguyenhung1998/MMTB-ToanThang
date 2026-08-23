<?php

use App\Http\Controllers\Api\AiReconciliationController;
use App\Http\Controllers\Api\OcrJobController;
use App\Http\Controllers\Api\ZaloMessageController;
use App\Http\Middleware\AuthenticateCollector;
use App\Http\Middleware\AuthenticateOpenClaw;
use App\Http\Middleware\AuthenticateOcrWorker;
use Illuminate\Support\Facades\Route;

Route::prefix('collector/v1')
    ->middleware([AuthenticateCollector::class, 'throttle:120,1'])
    ->group(function (): void {
        Route::post('/zalo/messages', [ZaloMessageController::class, 'store'])
            ->name('api.collector.zalo-messages.store');
    });

Route::prefix('openclaw/v1')
    ->middleware([AuthenticateOpenClaw::class, 'throttle:120,1'])
    ->group(function (): void {
        Route::post('/reconciliation/jobs/claim', [AiReconciliationController::class, 'claim'])
            ->name('api.openclaw.reconciliation-jobs.claim');
        Route::get('/reconciliation/jobs/{aiReconciliationJob}/images/{ocrJob}', [AiReconciliationController::class, 'image'])
            ->name('api.openclaw.reconciliation-jobs.images.show');
        Route::post('/reconciliation/jobs/{aiReconciliationJob}/complete', [AiReconciliationController::class, 'complete'])
            ->name('api.openclaw.reconciliation-jobs.complete');
        Route::post('/reconciliation/jobs/{aiReconciliationJob}/fail', [AiReconciliationController::class, 'fail'])
            ->name('api.openclaw.reconciliation-jobs.fail');
    });

Route::prefix('ocr/v1')
    ->middleware([AuthenticateOcrWorker::class, 'throttle:120,1'])
    ->group(function (): void {
        Route::get('/machines', [OcrJobController::class, 'machines'])
            ->name('api.ocr.machines.index');
        Route::post('/jobs/claim', [OcrJobController::class, 'claim'])
            ->name('api.ocr.jobs.claim');
        Route::get('/jobs/{ocrJob}/image', [OcrJobController::class, 'image'])
            ->name('api.ocr.jobs.image');
        Route::post('/jobs/{ocrJob}/classify', [OcrJobController::class, 'classify'])
            ->name('api.ocr.jobs.classify');
        Route::post('/jobs/{ocrJob}/complete', [OcrJobController::class, 'complete'])
            ->name('api.ocr.jobs.complete');
        Route::post('/jobs/{ocrJob}/complete-journal', [OcrJobController::class, 'completeJournal'])
            ->name('api.ocr.jobs.complete-journal');
        Route::post('/jobs/{ocrJob}/fail', [OcrJobController::class, 'fail'])
            ->name('api.ocr.jobs.fail');
    });
