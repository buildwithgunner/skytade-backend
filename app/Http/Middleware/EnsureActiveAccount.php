<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->account_status !== 'active') {
            return new JsonResponse([
                'message' => 'Your account is currently restricted. Please contact support.',
            ], 423);
        }

        return $next($request);
    }
}
