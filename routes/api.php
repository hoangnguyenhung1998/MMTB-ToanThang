<?php

use App\Http\Controllers\Api\AiReconciliationController;
use App\Http\Controllers\Api\OcrJobController;
use App\Http\Controllers\Api\MachineIntakeOcrController;
use App\Http\Controllers\Api\OpenClawCommandController;
use App\Http\Controllers\Api\ZaloMessageController;
use App\Http\Controllers\Api\AutomationHeartbeatController;
use App\Http\Controllers\Api\AutomationOperationalCommandController;
use App\Http\Controllers\Api\MachineIntakeEmailReplyController;
use App\Http\Middleware\AuthenticateAutomationAgent;
use App\Http\Middleware\AuthenticateCollector;
use App\Http\Middleware\AuthenticateOpenClaw;
use App\Http\Middleware\AuthenticateOcrWorker;
use App\Http\Middleware\AuthenticateGmailIntakeWorker;
use Illuminate\Support\Facades\Route;

Route::post('/automation/v1/heartbeat', AutomationHeartbeatController::class)
    ->middleware([AuthenticateAutomationAgent::class, 'throttle:120,1'])
    ->name('api.automation.heartbeat');

Route::prefix('automation/v1')->middleware([AuthenticateAutomationAgent::class, 'throttle:120,1'])->group(function (): void {
    Route::post('/commands/claim', [AutomationOperationalCommandController::class, 'claim'])->name('api.automation.commands.claim');
    Route::post('/commands/{command}/complete', [AutomationOperationalCommandController::class, 'complete'])->name('api.automation.commands.complete');
    Route::post('/commands/{command}/fail', [AutomationOperationalCommandController::class, 'fail'])->name('api.automation.commands.fail');
});

Route::prefix('collector/v1')
    ->middleware([AuthenticateCollector::class, 'throttle:collector-api'])
    ->group(function (): void {
        Route::post('/zalo/messages', [ZaloMessageController::class, 'store'])
            ->name('api.collector.zalo-messages.store');
    });

Route::prefix('gmail-intake/v1')
    ->middleware([AuthenticateGmailIntakeWorker::class, 'throttle:60,1'])
    ->group(function (): void {
        Route::post('/replies', [MachineIntakeEmailReplyController::class, 'store'])
            ->name('api.gmail-intake.replies.store');
    });

Route::prefix('openclaw/v1')
    ->middleware([AuthenticateOpenClaw::class, 'throttle:openclaw-api'])
    ->group(function (): void {
        Route::post('/reconciliation/jobs/claim', [AiReconciliationController::class, 'claim'])
            ->name('api.openclaw.reconciliation-jobs.claim');
        Route::get('/reconciliation/jobs/{aiReconciliationJob}/images/{ocrJob}', [AiReconciliationController::class, 'image'])
            ->name('api.openclaw.reconciliation-jobs.images.show');
        Route::post('/reconciliation/jobs/{aiReconciliationJob}/complete', [AiReconciliationController::class, 'complete'])
            ->name('api.openclaw.reconciliation-jobs.complete');
        Route::post('/reconciliation/jobs/{aiReconciliationJob}/fail', [AiReconciliationController::class, 'fail'])
            ->name('api.openclaw.reconciliation-jobs.fail');
        Route::post('/commands/claim', [OpenClawCommandController::class, 'claim'])
            ->name('api.openclaw.commands.claim');
        Route::post('/commands/{openClawCommand}/complete', [OpenClawCommandController::class, 'complete'])
            ->name('api.openclaw.commands.complete');
        Route::post('/commands/{openClawCommand}/fail', [OpenClawCommandController::class, 'fail'])
            ->name('api.openclaw.commands.fail');
    });

Route::prefix('ocr/v1')
    ->middleware([AuthenticateOcrWorker::class, 'throttle:ocr-worker-api'])
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
        Route::post('/intake/jobs/claim', [MachineIntakeOcrController::class, 'claim'])->name('api.ocr.intake.claim');
        Route::get('/intake/jobs/{machineIntakeOcrJob}/image', [MachineIntakeOcrController::class, 'image'])->name('api.ocr.intake.image');
        Route::post('/intake/jobs/{machineIntakeOcrJob}/complete', [MachineIntakeOcrController::class, 'complete'])->name('api.ocr.intake.complete');
        Route::post('/intake/jobs/{machineIntakeOcrJob}/fail', [MachineIntakeOcrController::class, 'fail'])->name('api.ocr.intake.fail');
        Route::post('/handovers/jobs/claim', [App\Http\Controllers\Api\MachineHandoverOcrController::class, 'claim'])->name('api.ocr.handovers.claim');
        Route::get('/handovers/jobs/{machineHandoverOcrJob}/image', [App\Http\Controllers\Api\MachineHandoverOcrController::class, 'image'])->name('api.ocr.handovers.image');
        Route::post('/handovers/jobs/{machineHandoverOcrJob}/complete', [App\Http\Controllers\Api\MachineHandoverOcrController::class, 'complete'])->name('api.ocr.handovers.complete');
        Route::post('/handovers/jobs/{machineHandoverOcrJob}/fail', [App\Http\Controllers\Api\MachineHandoverOcrController::class, 'fail'])->name('api.ocr.handovers.fail');
    });
