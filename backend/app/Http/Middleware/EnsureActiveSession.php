<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveSession
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $bearer = $request->bearerToken();
        // Must match AuthController: Sanctum sends "id|secret"; we store hash of secret only.
        $secret = $bearer && str_contains($bearer, '|')
            ? explode('|', $bearer, 2)[1]
            : $bearer;
        if (! $secret || $user->active_session_token !== hash('sha256', $secret)) {
            return response()->json(['message' => 'Session is no longer active.'], 401);
        }

        return $next($request);
    }
}
