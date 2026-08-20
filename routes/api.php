<?php

use App\Http\Controllers\Api\ZaloMessageController;
use App\Http\Middleware\AuthenticateCollector;
use Illuminate\Support\Facades\Route;

Route::prefix('collector/v1')
    ->middleware([AuthenticateCollector::class, 'throttle:120,1'])
    ->group(function (): void {
        Route::post('/zalo/messages', [ZaloMessageController::class, 'store'])
            ->name('api.collector.zalo-messages.store');
    });
