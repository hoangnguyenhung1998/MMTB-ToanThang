<?php

use App\Http\Controllers\Api\ZaloMessageController;
use App\Http\Middleware\AuthenticateCollector;
use App\Http\Controllers\Api\OcrJobController;
use App\Http\Middleware\AuthenticateOcrWorker;
use Illuminate\Support\Facades\Route;

Route::prefix('collector/v1')
    ->middleware([AuthenticateCollector::class, 'throttle:120,1'])
    ->group(function (): void {
        Route::post('/zalo/messages', [ZaloMessageController::class, 'store'])
            ->name('api.collector.zalo-messages.store');
    });

Route::prefix('ocr/v1')
    ->middleware([AuthenticateOcrWorker::class, 'throttle:120,1'])
    ->group(function (): void {
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
