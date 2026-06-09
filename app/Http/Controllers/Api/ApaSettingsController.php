<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SaaS\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ApaSettingsController extends Controller
{
    public function __construct(
        private readonly SubscriptionService $subscriptions,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'settings' => $this->subscriptions->resolvedApaSettings($user),
            'defaults' => $this->subscriptions->defaultApaSettings(),
            'can_customize' => $this->subscriptions->canCustomizeApa($user),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->subscriptions->assertCanCustomizeApa($user);

        $validated = $request->validate([
            'settings' => ['required', 'array'],
            'settings.margins_in' => ['sometimes', 'array'],
            'settings.margins_in.top' => ['sometimes', 'numeric', 'min:0.5', 'max:2'],
            'settings.margins_in.bottom' => ['sometimes', 'numeric', 'min:0.5', 'max:2'],
            'settings.margins_in.left' => ['sometimes', 'numeric', 'min:0.5', 'max:2'],
            'settings.margins_in.right' => ['sometimes', 'numeric', 'min:0.5', 'max:2'],
            'settings.line_spacing' => ['sometimes', 'numeric', 'min:1', 'max:3'],
            'settings.font' => ['sometimes', 'string', 'max:64'],
            'settings.font_size_pt' => ['sometimes', 'integer', 'min:10', 'max:14'],
            'settings.title_case' => ['sometimes', 'string', 'in:sentence,title'],
            'settings.page_number_position' => ['sometimes', 'string', 'in:header_top_right,footer_center'],
            'settings.heading_levels' => ['sometimes', 'array'],
        ]);

        $user->forceFill([
            'apa_settings' => $validated['settings'],
        ])->save();

        return response()->json([
            'message' => 'Configuración APA guardada.',
            'settings' => $this->subscriptions->resolvedApaSettings($user->fresh()),
            'can_customize' => true,
        ]);
    }
}
