<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\University;
use App\Services\SaaS\CareerSelectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class UniversityController extends Controller
{
    public function index(Request $request, CareerSelectionService $careerSelection): JsonResponse
    {
        $user = Auth::guard('sanctum')->user();

        $items = $careerSelection->universitiesFor($user)->get()->map(
            fn (University $u) => $careerSelection->serializeUniversity($u)
        );

        return response()->json(['data' => $items]);
    }

    public function careers(Request $request, University $university, CareerSelectionService $careerSelection): JsonResponse
    {
        abort_unless($university->status === 'active', 404);

        $user = Auth::guard('sanctum')->user();

        $careers = $careerSelection->careersForUniversity($user, $university)
            ->with('documentTemplate')
            ->get()
            ->map(fn ($c) => $careerSelection->serializeCareer($c));

        return response()->json([
            'university' => $careerSelection->serializeUniversity($university),
            'data' => $careers,
        ]);
    }
}
