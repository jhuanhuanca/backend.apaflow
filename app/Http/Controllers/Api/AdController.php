<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SaaS\AdsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdController extends Controller
{
    public function __construct(
        private readonly AdsService $ads,
    ) {}

    public function placements(Request $request): JsonResponse
    {
        $location = (string) $request->query('location', 'home_top');

        $user = $request->user();
        $items = $this->ads->activePlacements($location, $user);

        return response()->json([
            'show_ads' => $this->ads->canShowAds($user),
            'location' => $location,
            'placements' => $this->ads->serializePlacements($items),
        ]);
    }
}
