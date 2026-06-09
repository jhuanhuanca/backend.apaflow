<?php

namespace App\Modules\Careers\Http\Controllers;

use App\Http\Controllers\Concerns\StoresPublicUploads;
use App\Http\Controllers\Controller;
use App\Models\Career;
use App\Models\ContentCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminCareerController extends Controller
{
    use StoresPublicUploads;

    public function index(Request $request): JsonResponse
    {
        $q = Career::query()->with(['university', 'documentTemplate'])->orderBy('name');

        if ($request->filled('university_id')) {
            $q->where('university_id', $request->integer('university_id'));
        }

        return response()->json($q->paginate($request->integer('per_page', 25)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'university_id' => ['required', 'exists:universities,id'],
            'content_category_id' => ['nullable', 'exists:content_categories,id'],
            'document_template_id' => ['nullable', 'exists:document_templates,id'],
            'name' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:64'],
            'description' => ['nullable', 'string'],
            'logo' => ['nullable', 'image', 'max:4096'],
            'logo_path' => ['nullable', 'string', 'max:2048'],
            'status' => ['required', 'string', 'in:active,inactive,draft'],
            'plan_access' => ['required', 'string', 'in:free,pro,both'],
        ]);

        $this->assertCategoryBelongsToUniversity($data['university_id'], $data['content_category_id'] ?? null);

        $path = $this->storePublicFile($request, 'logo', 'careers/logos');
        unset($data['logo']);
        if ($path) {
            $data['logo_path'] = $path;
        }

        $career = Career::query()->create($data);

        return response()->json($career->load(['university', 'documentTemplate']), 201);
    }

    public function show(Career $career): JsonResponse
    {
        return response()->json($career->load(['university', 'contentCategory', 'documentTemplate']));
    }

    public function update(Request $request, Career $career): JsonResponse
    {
        $data = $request->validate([
            'university_id' => ['sometimes', 'exists:universities,id'],
            'content_category_id' => ['nullable', 'exists:content_categories,id'],
            'document_template_id' => ['nullable', 'exists:document_templates,id'],
            'name' => ['sometimes', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:64'],
            'description' => ['nullable', 'string'],
            'logo' => ['nullable', 'image', 'max:4096'],
            'logo_path' => ['nullable', 'string', 'max:2048'],
            'status' => ['sometimes', 'string', 'in:active,inactive,draft'],
            'plan_access' => ['sometimes', 'string', 'in:free,pro,both'],
        ]);

        $universityId = $data['university_id'] ?? $career->university_id;
        $this->assertCategoryBelongsToUniversity($universityId, $data['content_category_id'] ?? $career->content_category_id);

        if ($request->hasFile('logo')) {
            $this->deletePublicFile($career->logo_path);
            $path = $this->storePublicFile($request, 'logo', 'careers/logos');
            if ($path) {
                $data['logo_path'] = $path;
            }
        }

        unset($data['logo']);
        $career->update($data);

        return response()->json($career->fresh()->load(['university', 'contentCategory', 'documentTemplate']));
    }

    public function destroy(Career $career): JsonResponse
    {
        $this->deletePublicFile($career->logo_path);
        $career->delete();

        return response()->json(null, 204);
    }

    private function assertCategoryBelongsToUniversity(int $universityId, ?int $categoryId): void
    {
        if ($categoryId === null) {
            return;
        }

        $ok = ContentCategory::query()
            ->where('id', $categoryId)
            ->where('university_id', $universityId)
            ->exists();

        abort_unless($ok, 422, 'La categoría no pertenece a la universidad indicada.');
    }
}
