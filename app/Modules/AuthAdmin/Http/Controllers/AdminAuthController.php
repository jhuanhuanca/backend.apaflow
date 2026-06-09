<?php

namespace App\Modules\AuthAdmin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AdminAuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $admin = Admin::query()->where('email', $data['email'])->with('roles')->first();

        if (! $admin || ! Hash::check($data['password'], $admin->password)) {
            throw ValidationException::withMessages([
                'email' => ['Credenciales incorrectas.'],
            ]);
        }

        if (! $admin->is_active) {
            throw ValidationException::withMessages([
                'email' => ['Cuenta desactivada.'],
            ]);
        }

        $admin->tokens()->where('name', 'admin-panel')->delete();
        $token = $admin->createToken('admin-panel', ['*'], now()->addDays(7))->plainTextToken;

        return response()->json([
            'token' => $token,
            'token_type' => 'Bearer',
            'admin' => $this->adminPayload($admin),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user('admin')?->currentAccessToken()?->delete();

        return response()->json(['message' => 'Sesión admin cerrada.']);
    }

    public function me(Request $request): JsonResponse
    {
        $admin = $request->user('admin');
        $admin?->load('roles');

        return response()->json($admin ? $this->adminPayload($admin) : null);
    }

    private function adminPayload(Admin $admin): array
    {
        return [
            'id' => $admin->id,
            'name' => $admin->name,
            'email' => $admin->email,
            'is_active' => $admin->is_active,
            'roles' => $admin->roles->pluck('name'),
        ];
    }
}
