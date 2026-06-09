<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tras auth:admin, verifica que la cuenta siga activa.
 */
class EnsureAdminIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $admin = $request->user('admin');

        if (! $admin || ! $admin->is_active) {
            abort(Response::HTTP_FORBIDDEN, 'Cuenta de administrador desactivada.');
        }

        return $next($request);
    }
}
