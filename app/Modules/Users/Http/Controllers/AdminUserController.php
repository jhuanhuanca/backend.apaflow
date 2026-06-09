<?php

namespace App\Modules\Users\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class AdminUserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = User::query()->orderByDesc('id');

        if ($search = $request->string('q')->toString()) {
            $q->where(function ($w) use ($search) {
                $w->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($plan = $request->string('plan')->toString()) {
            $q->where('plan', $plan);
        }

        if ($request->boolean('blocked')) {
            $q->where('is_blocked', true);
        }

        /** @var LengthAwarePaginator $page */
        $page = $q->paginate($request->integer('per_page', 20));

        return response()->json($page);
    }

    public function show(User $user): JsonResponse
    {
        $user->loadCount(['documents', 'payments']);

        return response()->json($user);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'plan' => ['sometimes', 'string', 'in:free,pro,trial'],
            'subscription_status' => ['sometimes', 'string', 'in:active,trial,expired,canceled,past_due'],
            'trial_ends_at' => ['nullable', 'date'],
            'is_blocked' => ['sometimes', 'boolean'],
        ]);

        $user->fill($data);
        $user->save();

        return response()->json($user->fresh());
    }
}
