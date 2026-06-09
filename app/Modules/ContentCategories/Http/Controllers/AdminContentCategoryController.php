<?php

namespace App\Modules\ContentCategories\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\ContentCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminContentCategoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = ContentCategory::query()->with(['university', 'parent'])->orderBy('sort_order');

        if ($request->filled('university_id')) {
            $q->where('university_id', $request->integer('university_id'));
        }

        return response()->json($q->paginate($request->integer('per_page', 50)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'university_id' => ['required', 'exists:universities,id'],
            'parent_id' => ['nullable', 'exists:content_categories,id'],
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9\-]+$/'],
            'description' => ['nullable', 'string'],
            'status' => ['required', 'string', 'in:active,inactive,draft'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $this->assertParentUniversity($data['university_id'], $data['parent_id'] ?? null);

        $category = ContentCategory::query()->create($data);

        return response()->json($category, 201);
    }

    public function show(ContentCategory $contentCategory): JsonResponse
    {
        return response()->json($contentCategory->load(['university', 'parent', 'children']));
    }

    public function update(Request $request, ContentCategory $contentCategory): JsonResponse
    {
        $data = $request->validate([
            'parent_id' => ['nullable', 'exists:content_categories,id'],
            'name' => ['sometimes', 'string', 'max:255'],
            'slug' => ['sometimes', 'string', 'max:255', 'regex:/^[a-z0-9\-]+$/'],
            'description' => ['nullable', 'string'],
            'status' => ['sometimes', 'string', 'in:active,inactive,draft'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $this->assertParentUniversity($contentCategory->university_id, $data['parent_id'] ?? $contentCategory->parent_id);

        $contentCategory->update($data);

        return response()->json($contentCategory->fresh());
    }

    public function destroy(ContentCategory $contentCategory): JsonResponse
    {
        $contentCategory->delete();

        return response()->json(null, 204);
    }

    private function assertParentUniversity(int $universityId, ?int $parentId): void
    {
        if ($parentId === null) {
            return;
        }

        $ok = ContentCategory::query()
            ->where('id', $parentId)
            ->where('university_id', $universityId)
            ->exists();

        abort_unless($ok, 422, 'La categoría padre debe pertenecer a la misma universidad.');
    }
}
