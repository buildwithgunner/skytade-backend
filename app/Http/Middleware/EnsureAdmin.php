<?php

namespace App\Http\Middleware;

use App\Models\Admin;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $admin = $request->user('admin') ?? $request->user();

        if (! $admin instanceof Admin) {
            return new JsonResponse([
                'message' => 'Administrator access is required.',
            ], 403);
        }

        // Ensure both guards (default + admin) resolve to the same admin
        $request->setUserResolver(fn () => $admin);

        return $next($request);
    }
}
