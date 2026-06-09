<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Resuelve el usuario en rutas /api/* tanto con sesión Sanctum SPA (cookie)
 * como con token Bearer (app móvil Flutter).
 */
final class ApiUserResolver
{
    public static function resolve(Request $request): ?User
    {
        $user = $request->user();
        if ($user instanceof User) {
            return $user;
        }

        if ($request->bearerToken()) {
            $tokenUser = Auth::guard('sanctum')->user();
            if ($tokenUser instanceof User) {
                return $tokenUser;
            }
        }

        return null;
    }
}
