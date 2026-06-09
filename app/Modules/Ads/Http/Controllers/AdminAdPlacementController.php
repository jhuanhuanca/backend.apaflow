<?php

namespace App\Modules\Ads\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AdPlacement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminAdPlacementController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = AdPlacement::query()->orderBy('priority')->orderByDesc('id');

        if ($request->filled('location')) {
            $q->where('location', $request->string('location'));
        }

        if ($request->filled('status')) {
            $q->where('status', $request->string('status'));
        }

        return response()->json($q->paginate($request->integer('per_page', 25)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'location' => ['required', 'string', 'max:64'],
            'format' => ['required', 'string', 'in:banner,sidebar,inline,footer'],
            'status' => ['required', 'string', 'in:active,inactive'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'audience' => ['required', 'string', 'in:guest,free,guest_and_free,non_pro,all'],
            'provider' => ['nullable', 'string', 'max:32'],
            'slot_id' => ['nullable', 'string', 'max:255'],
            'label' => ['nullable', 'string', 'max:120'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'metadata' => ['nullable', 'array'],
        ]);

        $data['priority'] = $data['priority'] ?? 100;
        $data['provider'] = $data['provider'] ?? 'placeholder';

        $ad = AdPlacement::query()->create($data);

        return response()->json($ad, 201);
    }

    public function show(AdPlacement $adPlacement): JsonResponse
    {
        return response()->json($adPlacement);
    }

    public function update(Request $request, AdPlacement $adPlacement): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'location' => ['sometimes', 'string', 'max:64'],
            'format' => ['sometimes', 'string', 'in:banner,sidebar,inline,footer'],
            'status' => ['sometimes', 'string', 'in:active,inactive'],
            'priority' => ['sometimes', 'integer', 'min:0', 'max:9999'],
            'audience' => ['sometimes', 'string', 'in:guest,free,guest_and_free,non_pro,all'],
            'provider' => ['nullable', 'string', 'max:32'],
            'slot_id' => ['nullable', 'string', 'max:255'],
            'label' => ['nullable', 'string', 'max:120'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
            'metadata' => ['nullable', 'array'],
        ]);

        $adPlacement->update($data);

        return response()->json($adPlacement->fresh());
    }

    public function destroy(AdPlacement $adPlacement): JsonResponse
    {
        $adPlacement->delete();

        return response()->json(null, 204);
    }
}
