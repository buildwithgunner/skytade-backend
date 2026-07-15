<?php

namespace App\Http\Middleware;

use App\Models\Admin;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminPermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $actor = $request->user('admin') ?? $request->user();

        if (! $actor instanceof Admin) {
            return new JsonResponse([
                'message' => 'Administrator access is required.',
            ], 403);
        }

        if (! $actor->hasAdminPermission($permission)) {
            return new JsonResponse([
                'message' => 'You do not have permission to perform this administrative action.',
            ], 403);
        }

        return $next($request);
    }
}
