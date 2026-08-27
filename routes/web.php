<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('login');
});

Route::get('/dashboard', [App\Http\Controllers\DashboardController::class, 'index'])
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/machine-intakes', [App\Http\Controllers\MachineIntakeController::class, 'index'])->name('machine-intakes.index');
    Route::get('/machine-intakes/create', [App\Http\Controllers\MachineIntakeController::class, 'create'])->name('machine-intakes.create');
    Route::post('/machine-intakes', [App\Http\Controllers\MachineIntakeController::class, 'store'])->name('machine-intakes.store');
    Route::get('/machine-intakes/{machineIntake}', [App\Http\Controllers\MachineIntakeController::class, 'show'])->name('machine-intakes.show');
    Route::put('/machine-intakes/{machineIntake}/confirm', [App\Http\Controllers\MachineIntakeController::class, 'confirm'])->name('machine-intakes.confirm');
    Route::post('/machine-intakes/{machineIntake}/email-sent', [App\Http\Controllers\MachineIntakeController::class, 'markEmailSent'])->name('machine-intakes.email-sent');
    Route::post('/machine-intakes/{machineIntake}/assign-code', [App\Http\Controllers\MachineIntakeController::class, 'assignCode'])->name('machine-intakes.assign-code');
    Route::post('/machine-intakes/{machineIntake}/requeue', [App\Http\Controllers\MachineIntakeController::class, 'requeue'])->name('machine-intakes.requeue');
    Route::get('/machine-intakes/{machineIntake}/documents/{document}', [App\Http\Controllers\MachineIntakeController::class, 'document'])->name('machine-intakes.documents.show');

    Route::get('/projects', [App\Http\Controllers\ProjectController::class, 'index'])
        ->name('projects.index');
    Route::get('/projects/create', [App\Http\Controllers\ProjectController::class, 'create'])
        ->name('projects.create');
    Route::post('/projects', [App\Http\Controllers\ProjectController::class, 'store'])
        ->name('projects.store');
    Route::get('/projects/{project}/edit', [App\Http\Controllers\ProjectController::class, 'edit'])
        ->name('projects.edit');
    Route::put('/projects/{project}', [App\Http\Controllers\ProjectController::class, 'update'])
        ->name('projects.update');
    Route::post('/projects/{project}/delete', [App\Http\Controllers\ProjectController::class, 'destroy'])
        ->name('projects.delete');

    Route::get('/projects/{project}/command-centers', [App\Http\Controllers\CommandCenterController::class, 'projectIndex'])
        ->name('project-command-centers.index');
    Route::post('/projects/{project}/command-centers', [App\Http\Controllers\CommandCenterController::class, 'projectStore'])
        ->name('project-command-centers.store');

    Route::get('/command-centers', [App\Http\Controllers\CommandCenterController::class, 'index'])
        ->name('command-centers.index');
    Route::get('/command-centers/{id}/edit', [App\Http\Controllers\CommandCenterController::class, 'edit'])
        ->name('command-centers.edit');
    Route::put('/command-centers/{id}', [App\Http\Controllers\CommandCenterController::class, 'update'])
        ->name('command-centers.update');
    Route::post('/command-centers/{id}/delete', [App\Http\Controllers\CommandCenterController::class, 'destroy'])
        ->name('command-centers.delete');

    Route::get('/machines', [App\Http\Controllers\MachineController::class, 'index'])
        ->name('machines.index');
    Route::get('/machines/create', [App\Http\Controllers\MachineController::class, 'create'])
        ->name('machines.create');

    Route::prefix('machines/wizard')->name('machines.wizard.')->group(function () {
    Route::get('/', [App\Http\Controllers\MachineWizardController::class, 'index'])->name('index');
    Route::get('/step-1', [App\Http\Controllers\MachineWizardController::class, 'step1'])->name('step1');
    Route::post('/step-1', [App\Http\Controllers\MachineWizardController::class, 'storeStep1'])->name('step1.store');
    Route::get('/step-2', [App\Http\Controllers\MachineWizardController::class, 'step2'])->name('step2');
    Route::post('/step-2', [App\Http\Controllers\MachineWizardController::class, 'storeStep2'])->name('step2.store');
    Route::get('/step-3', [App\Http\Controllers\MachineWizardController::class, 'step3'])->name('step3');
    Route::post('/step-3', [App\Http\Controllers\MachineWizardController::class, 'storeStep3'])->name('step3.store');
    Route::get('/review', [App\Http\Controllers\MachineWizardController::class, 'review'])->name('review');
    Route::post('/finish', [App\Http\Controllers\MachineWizardController::class, 'finish'])->name('finish');
    Route::post('/cancel', [App\Http\Controllers\MachineWizardController::class, 'cancel'])->name('cancel');
    });
    
    Route::get('/machines/import', [App\Http\Controllers\MachineImportController::class, 'form'])
        ->name('machines.import.form');
    Route::post('/machines/import', [App\Http\Controllers\MachineImportController::class, 'import'])
        ->name('machines.import');
    Route::get('/machines/import/template', [App\Http\Controllers\MachineImportController::class, 'template'])
        ->name('machines.import.template');
    Route::get('/machines/import-years/template', [App\Http\Controllers\MachineImportController::class, 'manufactureYearTemplate'])
        ->name('machines.import-years.template');
    Route::post('/machines/import-years', [App\Http\Controllers\MachineImportController::class, 'importManufactureYears'])
        ->name('machines.import-years');
    Route::get('/machines/export', [App\Http\Controllers\MachineController::class, 'export'])
        ->name('machines.export');
    Route::post('/machines', [App\Http\Controllers\MachineController::class, 'store'])
        ->name('machines.store');
    Route::post('/machines/batch/handover', [App\Http\Controllers\MachineBatchController::class, 'handover'])
        ->name('machines.batch.handover');
    Route::post('/machines/batch/activate', [App\Http\Controllers\MachineBatchController::class, 'activate'])
        ->name('machines.batch.activate');
    Route::post('/machines/batch/export', [App\Http\Controllers\MachineBatchController::class, 'exportSelected'])
        ->name('machines.batch.export');
    Route::post('/machines/batch/delete', [App\Http\Controllers\MachineBatchController::class, 'delete'])
        ->name('machines.batch.delete');
    Route::get('/machines/{machine}/edit', [App\Http\Controllers\MachineController::class, 'edit'])
        ->name('machines.edit');
    Route::put('/machines/{machine}', [App\Http\Controllers\MachineController::class, 'update'])
        ->name('machines.update');
    Route::post('/machines/{machine}/delete', [App\Http\Controllers\MachineController::class, 'destroy'])
        ->name('machines.delete');
    Route::get('/machines/{machine}', [App\Http\Controllers\MachineController::class, 'show'])
        ->name('machines.show');
    Route::get('/machines/{machine}/change-driver', [App\Http\Controllers\MachineController::class, 'changeDriverForm'])
        ->name('machines.change-driver.form');
    Route::post('/machines/{machine}/change-driver', [App\Http\Controllers\MachineController::class, 'changeDriverSubmit'])
        ->name('machines.change-driver.submit');
    Route::get('/machines/{machine}/timeline', [App\Http\Controllers\MachineTimelineController::class, 'index'])
        ->name('machines.timeline');

    Route::post('/machines/{machine}/documents', [App\Http\Controllers\MachineDocumentController::class, 'store'])
        ->name('machine-documents.store');
    Route::get('/machines/{machine}/documents', [App\Http\Controllers\MachineDocumentController::class, 'index'])
        ->name('machine-documents.index');
    Route::get('/machines/{machine}/documents/create', [App\Http\Controllers\MachineDocumentController::class, 'create'])
        ->name('machine-documents.create');
    Route::get('/machines/{machine}/documents/{document}/edit', [App\Http\Controllers\MachineDocumentController::class, 'edit'])
        ->name('machine-documents.edit');
    Route::put('/machines/{machine}/documents/{document}', [App\Http\Controllers\MachineDocumentController::class, 'update'])
        ->name('machine-documents.update');
    Route::post('/machines/{machine}/documents/{document}/delete', [App\Http\Controllers\MachineDocumentController::class, 'destroy'])
        ->name('machine-documents.delete');

    Route::get('/machines/{machine}/events/{event}/edit-proof', [App\Http\Controllers\MachineEventProofController::class, 'edit'])
        ->name('machine-events.edit-proof');
    Route::put('/machines/{machine}/events/{event}/proof', [App\Http\Controllers\MachineEventProofController::class, 'update'])
        ->name('machine-events.update-proof');
    Route::delete('/machines/{machine}/events/{event}/proof', [App\Http\Controllers\MachineEventProofController::class, 'destroy'])
        ->name('machine-events.destroy-proof');

    Route::get('/machines/{machine}/handover', [App\Http\Controllers\MachineOpsController::class, 'handoverForm'])
        ->name('ops.handover.form');
    Route::post('/machines/{machine}/handover', [App\Http\Controllers\MachineOpsController::class, 'handoverSubmit'])
        ->name('ops.handover.submit');

    Route::post('/machines/{machine}/activate', [App\Http\Controllers\MachineOpsController::class, 'activateSubmit'])
        ->name('ops.activate.submit');

    Route::get('/machines/{machine}/transfer', [App\Http\Controllers\MachineOpsController::class, 'transferForm'])
        ->name('ops.transfer.form');
    Route::post('/machines/{machine}/transfer', [App\Http\Controllers\MachineOpsController::class, 'transferSubmit'])
        ->name('ops.transfer.submit');

    Route::get('/machines/{machine}/return', [App\Http\Controllers\MachineOpsController::class, 'returnForm'])
        ->name('ops.return.form');
    Route::post('/machines/{machine}/return', [App\Http\Controllers\MachineOpsController::class, 'returnSubmit'])
        ->name('ops.return.submit');
    Route::post('/machines/{machine}/return-app/mark', [App\Http\Controllers\MachineOpsController::class, 'markReturnedToApp'])
        ->name('ops.return-app.mark');

    Route::get('/machines/{machine}/assign-driver', [App\Http\Controllers\MachineOpsController::class, 'assignDriverForm'])
        ->name('ops.assign-driver.form');
    Route::post('/machines/{machine}/assign-driver', [App\Http\Controllers\MachineOpsController::class, 'assignDriverSubmit'])
        ->name('ops.assign-driver.submit');

    Route::get('/drivers', [App\Http\Controllers\DriverController::class, 'index'])
        ->name('drivers.index');
    Route::get('/drivers/create', [App\Http\Controllers\DriverController::class, 'create'])
        ->name('drivers.create');
    Route::get('/drivers/import', [App\Http\Controllers\DriverImportController::class, 'form'])
        ->name('drivers.import.form');
    Route::post('/drivers/import', [App\Http\Controllers\DriverImportController::class, 'import'])
        ->name('drivers.import');
    Route::get('/drivers/import/template', [App\Http\Controllers\DriverImportController::class, 'template'])
        ->name('drivers.import.template');
    Route::get('/drivers/export', [App\Http\Controllers\DriverController::class, 'export'])
        ->name('drivers.export');
    Route::post('/drivers', [App\Http\Controllers\DriverController::class, 'store'])
        ->name('drivers.store');
    Route::get('/drivers/{driver}', [App\Http\Controllers\DriverController::class, 'show'])
        ->name('drivers.show');
    Route::get('/drivers/{driver}/edit', [App\Http\Controllers\DriverController::class, 'edit'])
        ->name('drivers.edit');
    Route::put('/drivers/{driver}', [App\Http\Controllers\DriverController::class, 'update'])
        ->name('drivers.update');
    Route::post('/drivers/{driver}/delete', [App\Http\Controllers\DriverController::class, 'destroy'])
        ->name('drivers.delete');

    Route::post('/drivers/{driver}/documents', [App\Http\Controllers\DriverDocumentController::class, 'store'])
        ->name('driver-documents.store');
    Route::get('/drivers/{driver}/documents', [App\Http\Controllers\DriverDocumentController::class, 'index'])
        ->name('driver-documents.index');
    Route::get('/drivers/{driver}/documents/create', [App\Http\Controllers\DriverDocumentController::class, 'create'])
        ->name('driver-documents.create');
    Route::get('/drivers/{driver}/documents/{document}/edit', [App\Http\Controllers\DriverDocumentController::class, 'edit'])
        ->name('driver-documents.edit');
    Route::put('/drivers/{driver}/documents/{document}', [App\Http\Controllers\DriverDocumentController::class, 'update'])
        ->name('driver-documents.update');
    Route::post('/drivers/{driver}/documents/{document}/delete', [App\Http\Controllers\DriverDocumentController::class, 'destroy'])
        ->name('driver-documents.delete');

    Route::get('/expiries', [App\Http\Controllers\ExpiryController::class, 'index'])
        ->name('expiries.index');

    Route::get('/operation-center', [App\Http\Controllers\OperationCenterController::class, 'index'])
    ->name('operation-center.index');

    Route::get('/search', [App\Http\Controllers\GlobalSearchController::class, 'index'])
    ->name('global-search.index');

    Route::get('/search/quick', [App\Http\Controllers\GlobalSearchController::class, 'quick'])
        ->name('global-search.quick');

    Route::get('/activities', [App\Http\Controllers\ActivityController::class, 'index'])
    ->name('activities.index');

    Route::get('/ocr-reviews', [App\Http\Controllers\OcrReviewController::class, 'index'])
        ->name('ocr-reviews.index');
    Route::get('/daily-images', [App\Http\Controllers\DailyImageArchiveController::class, 'index'])
        ->name('daily-images.index');
    Route::get('/daily-images/export', [App\Http\Controllers\DailyImageArchiveController::class, 'export'])
        ->name('daily-images.export');
    Route::get('/daily-images/exceptions', [App\Http\Controllers\DailyImageArchiveController::class, 'exceptions'])
        ->name('daily-images.exceptions');
    Route::post('/ocr-reviews/bulk', [App\Http\Controllers\OcrReviewController::class, 'bulk'])
        ->name('ocr-reviews.bulk');
    Route::get('/ocr-reviews/{ocrJob}', [App\Http\Controllers\OcrReviewController::class, 'show'])
        ->name('ocr-reviews.show');
    Route::put('/ocr-reviews/{ocrJob}', [App\Http\Controllers\OcrReviewController::class, 'update'])
        ->name('ocr-reviews.update');
    Route::put('/ocr-reviews/{ocrJob}/journal', [App\Http\Controllers\OcrReviewController::class, 'updateJournal'])
        ->name('ocr-reviews.journal.update');
    Route::get('/ocr-reviews/{ocrJob}/image', [App\Http\Controllers\OcrReviewController::class, 'image'])
        ->name('ocr-reviews.image');

    Route::get('/ai-reconciliation', [App\Http\Controllers\AiReconciliationDashboardController::class, 'index'])
        ->name('ai-reconciliation.index');
    Route::get('/automation-health', [App\Http\Controllers\AutomationHealthDashboardController::class, 'index'])
        ->name('automation-health.index');
    Route::post('/automation-health/services/{automationService}/commands', [App\Http\Controllers\AutomationHealthDashboardController::class, 'storeCommand'])
        ->name('automation-health.commands.store');
    Route::get('/ai-reconciliation/{aiReconciliationJob}', [App\Http\Controllers\AiReconciliationDashboardController::class, 'show'])
        ->name('ai-reconciliation.show');
    Route::post('/ai-reconciliation/{aiReconciliationJob}/commands', [App\Http\Controllers\AiReconciliationDashboardController::class, 'storeCommand'])
        ->name('ai-reconciliation.commands.store');

    Route::get('/notifications', [App\Http\Controllers\NotificationController::class, 'index'])
    ->name('notifications.index');

    Route::post('/notifications/read-all', [App\Http\Controllers\NotificationController::class, 'readAll'])
        ->name('notifications.read-all');

    Route::post('/notifications/{notification}/read', [App\Http\Controllers\NotificationController::class, 'read'])
        ->name('notifications.read');

    Route::delete('/notifications/{notification}', [App\Http\Controllers\NotificationController::class, 'destroy'])
        ->name('notifications.destroy');

    Route::get('/notifications-unread-count', [App\Http\Controllers\NotificationController::class, 'unreadCount'])
        ->name('notifications.unread-count');

});

require __DIR__.'/auth.php';
