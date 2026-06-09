<?php

namespace App\Http\Controllers\Api;

use App\Enums\PlanAccess;
use App\Http\Controllers\Controller;
use App\Models\DocumentTemplate;
use App\Services\SaaS\AccessControlService;
use App\Services\SaaS\CareerSelectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class DocumentTemplateController extends Controller
{
    public function show(DocumentTemplate $documentTemplate, AccessControlService $access, CareerSelectionService $careerSelection): JsonResponse
    {
        abort_unless($documentTemplate->status === 'active', 404);

        $user = Auth::guard('sanctum')->user();
        $access->denyUnlessCanAccess($user, $documentTemplate->plan_access ?? PlanAccess::Both);

        return response()->json($careerSelection->serializeTemplate($documentTemplate));
    }
}
