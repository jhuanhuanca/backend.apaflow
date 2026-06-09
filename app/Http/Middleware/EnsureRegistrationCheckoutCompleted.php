<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\ApiUserResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Si la demo de checkout post-registro está activa, bloquea rutas hasta que el usuario complete el paso simulado.
 * Invitados (sin sesión) pasan. Si la demo está desactivada, no aplica restricción.
 */
class EnsureRegistrationCheckoutCompleted
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! (bool) config('payments.demo_upgrade_enabled', false)) {
            return $next($request);
        }

        $user = ApiUserResolver::resolve($request);
        if (! $user instanceof User) {
            return $next($request);
        }

        if ($user->registration_checkout_completed_at !== null) {
            return $next($request);
        }

        return response()->json([
            'message' => 'Completa el pago de bienvenida (simulación) para continuar.',
            'code' => 'REGISTRATION_CHECKOUT_REQUIRED',
        ], 403);
    }
}
