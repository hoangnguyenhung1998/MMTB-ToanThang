<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateOcrWorker
{
    public function handle(Request $request, Closure $next): Response
    {
        $configuredToken = (string) config('ocr.worker_token');
        $providedToken = (string) $request->bearerToken();

        if ($configuredToken === '' || $providedToken === '' || ! hash_equals($configuredToken, $providedToken)) {
            return new JsonResponse(['message' => 'Unauthenticated OCR worker.'], 401);
        }

        return $next($request);
    }
}
