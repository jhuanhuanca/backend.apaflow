<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\SaaS\SubscriptionService;
use App\Support\ApiUserResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Autenticación API:
 * - SPA Vue: guard web + sesión (cookie) + CSRF Sanctum stateful.
 * - App Flutter: enviar `device_name` en login/register → token Bearer Sanctum.
 */
class AuthController extends Controller
{
    public function __construct(
        private readonly SubscriptionService $subscriptions,
    ) {}

    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
        ]);

        if (! (bool) config('payments.demo_upgrade_enabled', false)) {
            $user->forceFill([
                'registration_checkout_completed_at' => now(),
            ])->save();
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        $fresh = $user->fresh();
        $payload = ['user' => $this->subscriptions->userPayload($fresh)];
        if ($request->filled('device_name')) {
            $payload['token'] = $fresh->createToken($request->string('device_name'))->plainTextToken;
        }

        return response()->json($payload, 201);
    }

    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::guard('web')->attempt($credentials)) {
            throw ValidationException::withMessages([
                'email' => ['Credenciales incorrectas.'],
            ]);
        }

        $request->session()->regenerate();

        /** @var User $user */
        $user = Auth::guard('web')->user();
        $payload = ['user' => $this->subscriptions->userPayload($user)];
        if ($request->filled('device_name')) {
            $payload['token'] = $user->createToken($request->string('device_name'))->plainTextToken;
        }

        return response()->json($payload);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user && $user->currentAccessToken()) {
            $user->currentAccessToken()->delete();

            return response()->json(['message' => 'Sesión cerrada.']);
        }

        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Sesión cerrada.']);
    }

    /**
     * Usuario actual o null (200). Soporta cookie SPA y Bearer móvil.
     */
    public function me(Request $request): JsonResponse
    {
        $user = ApiUserResolver::resolve($request);

        return response()->json($this->subscriptions->userPayload($user));
    }
}
