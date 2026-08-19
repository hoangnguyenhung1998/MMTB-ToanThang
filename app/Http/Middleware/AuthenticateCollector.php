<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateCollector
{
    public function handle(Request $request, Closure $next): Response
    {
        $configuredToken = (string) config('collector.token', '');
        $providedToken = (string) $request->bearerToken();

        if ($configuredToken === '' || $providedToken === '' || ! hash_equals($configuredToken, $providedToken)) {
            return new JsonResponse([
                'message' => 'Unauthenticated collector.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        return $next($request);
    }
}
