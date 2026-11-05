<?php

use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'can:manage-dossiers'])->prefix('dossiers')->name('dossiers.ai.')->controller(\App\Http\Controllers\DossierAiController::class)->group(function () {
    Route::post('/sources/{source}/ai', 'request')->name('request');
    Route::post('/ai/{run}/control', 'control')->name('control');
    Route::post('/ai/proposals/{proposal}/review', 'review')->name('review');
    Route::post('/ai/{run}/bulk', 'bulk')->name('bulk');
    Route::get('/ai/{run}/pages/{page}', 'image')->whereNumber('page')->name('image');
    Route::get('/ai-rules', 'rules')->name('rules');
    Route::post('/ai-rules', 'saveRule')->name('rules.save');
});

// Electronic dossiers share the existing authenticated asset/driver access boundary.
Route::middleware(['auth', 'can:manage-dossiers'])->prefix('dossiers')->name('dossiers.')->controller(\App\Http\Controllers\DossierController::class)->group(function () {
    Route::get('/', 'index')->name('index');
    Route::post('/batches', 'batch')->name('batches.create');
    Route::get('/batches/{batch}', 'showBatch')->name('batch');
    Route::post('/batches/{batch}/files', 'upload')->name('upload');
    Route::get('/sources/{source}', 'source')->name('source');
    Route::post('/sources/{source}', 'saveSource')->name('source.save');
    Route::post('/sources/{source}/retry', 'retrySource')->name('source.retry');
    Route::get('/sources/{source}/file/{kind}/{page?}', 'file')->whereNumber('page')->name('file');
    Route::post('/versions', 'version')->name('versions.create');
    Route::post('/versions/{version}', 'version')->name('versions.save');
    Route::post('/versions/{version}/archive', 'archive')->name('versions.archive');
    Route::get('/machines/{machine}', 'machine')->name('machine');
    Route::post('/machines/{machine}/group', 'group')->name('group');
    Route::post('/machines/{machine}/drivers', 'assign')->name('assign');
    Route::post('/assignments/{history}/close', 'closeAssignment')->name('assign.close');
    Route::get('/drivers/{driver}', 'driver')->name('driver');
    Route::post('/drivers/{driver}/sender', 'sender')->name('sender');
    Route::post('/senders/{link}/unlink', 'unlinkSender')->name('sender.unlink');
    Route::get('/types', 'types')->name('types');
    Route::post('/types/{type?}', 'type')->name('types.save');
    Route::get('/excel', 'excel')->name('excel');
    Route::post('/machines/{machine}/exports', 'export')->name('exports.create');
    Route::get('/exports/{export}', 'showExport')->name('export');
    Route::get('/exports/{export}/file', 'exportFile')->name('export.file');
    Route::post('/exports/{export}/retry', 'retryExport')->name('export.retry');
});
