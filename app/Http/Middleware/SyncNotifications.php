<?php

namespace App\Http\Middleware;

use Closure;
use App\Services\NotificationSyncService;

class SyncNotifications
{
    public function __construct(
        private NotificationSyncService $service
    ) {}

    public function handle($request, Closure $next)
    {
        if ($request->user() && ! $request->routeIs('ocr-monitoring.*')) {
            $this->service->syncForUser($request->user());
        }

        return $next($request);
    }
}
