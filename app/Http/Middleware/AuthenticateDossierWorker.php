<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class AuthenticateDossierWorker
{
    public function handle(Request $request, Closure $next)
    {
        $token = (string) config('dossiers.worker.token');
        abort_unless(strlen($token) >= 32 && hash_equals($token, (string) $request->bearerToken()), 401);

        return $next($request);
    }
}
