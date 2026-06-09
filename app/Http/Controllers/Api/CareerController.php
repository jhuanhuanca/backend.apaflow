<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Career;
use App\Services\SaaS\CareerSelectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CareerController extends Controller
{
    public function select(Request $request, CareerSelectionService $careerSelection): JsonResponse
    {
        $data = $request->validate([
            'career_id' => ['required', 'integer', 'exists:careers,id'],
        ]);

        $user = Auth::guard('sanctum')->user();
        $resolved = $careerSelection->resolveCareer($user, (int) $data['career_id']);

        return response()->json($careerSelection->toSelectionPayload($resolved));
    }

    public function resolve(Request $request, Career $career, CareerSelectionService $careerSelection): JsonResponse
    {
        $user = Auth::guard('sanctum')->user();
        $resolved = $careerSelection->resolveCareer($user, $career->id);

        return response()->json($careerSelection->toSelectionPayload($resolved));
    }
}
