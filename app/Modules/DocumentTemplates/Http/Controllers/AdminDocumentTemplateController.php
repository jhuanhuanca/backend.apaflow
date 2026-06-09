<?php

namespace App\Modules\DocumentTemplates\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\DocumentTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminDocumentTemplateController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = DocumentTemplate::query()->orderByDesc('id');

        if ($request->filled('type')) {
            $q->where('type', $request->string('type'));
        }

        return response()->json($q->paginate($request->integer('per_page', 25)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $template = DocumentTemplate::query()->create($data);

        return response()->json($template, 201);
    }

    public function show(DocumentTemplate $documentTemplate): JsonResponse
    {
        return response()->json($documentTemplate);
    }

    public function update(Request $request, DocumentTemplate $documentTemplate): JsonResponse
    {
        $data = $this->validated($request, partial: true);
        $documentTemplate->update($data);

        return response()->json($documentTemplate->fresh());
    }

    public function destroy(DocumentTemplate $documentTemplate): JsonResponse
    {
        $documentTemplate->delete();

        return response()->json(null, 204);
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $rules = [
            'name' => [$partial ? 'sometimes' : 'required', 'string', 'max:255'],
            'type' => [$partial ? 'sometimes' : 'required', 'string', 'in:apa,vancouver,custom'],
            'status' => [$partial ? 'sometimes' : 'required', 'string', 'in:active,inactive,draft'],
            'plan_access' => [$partial ? 'sometimes' : 'required', 'string', 'in:free,pro,both'],
            'settings' => ['nullable', 'array'],
            'description' => ['nullable', 'string'],
            'version' => ['nullable', 'integer', 'min:1'],
        ];

        return $request->validate($rules);
    }
}
