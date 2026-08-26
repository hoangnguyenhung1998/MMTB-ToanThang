<?php

namespace App\Http\Middleware;

use App\Models\AutomationNode;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateAutomationAgent
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) $request->bearerToken();
        $node = $token === '' ? null : AutomationNode::query()
            ->where('token_hash', hash('sha256', $token))
            ->where('enabled', true)
            ->first();

        if (! $node) {
            return new JsonResponse(['message' => 'Unauthenticated automation agent.'], 401);
        }

        $request->attributes->set('automation_node', $node);

        return $next($request);
    }
}
