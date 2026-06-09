<?php

namespace App\Modules\Universities\Http\Controllers;

use App\Http\Controllers\Concerns\StoresPublicUploads;
use App\Http\Controllers\Controller;
use App\Models\University;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminUniversityController extends Controller
{
    use StoresPublicUploads;

    public function index(Request $request): JsonResponse
    {
        $q = University::query()->orderBy('name');

        if ($request->filled('status')) {
            $q->where('status', $request->string('status'));
        }

        return response()->json($q->paginate($request->integer('per_page', 25)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'size:2'],
            'logo' => ['nullable', 'image', 'max:4096'],
            'logo_path' => ['nullable', 'string', 'max:2048'],
            'institution_type' => ['required', 'string', 'in:public,private,technical'],
            'status' => ['required', 'string', 'in:active,inactive,draft'],
            'plan_access' => ['required', 'string', 'in:free,pro,both'],
            'tenant_id' => ['nullable', 'integer'],
        ]);

        $path = $this->storePublicFile($request, 'logo', 'universities/logos');
        unset($data['logo']);
        if ($path) {
            $data['logo_path'] = $path;
        }

        $university = University::query()->create($data);

        return response()->json($university, 201);
    }

    public function show(University $university): JsonResponse
    {
        $university->load(['careers', 'contentCategories']);

        return response()->json($university);
    }

    public function update(Request $request, University $university): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'size:2'],
            'logo' => ['nullable', 'image', 'max:4096'],
            'logo_path' => ['nullable', 'string', 'max:2048'],
            'institution_type' => ['sometimes', 'string', 'in:public,private,technical'],
            'status' => ['sometimes', 'string', 'in:active,inactive,draft'],
            'plan_access' => ['sometimes', 'string', 'in:free,pro,both'],
            'tenant_id' => ['nullable', 'integer'],
        ]);

        if ($request->hasFile('logo')) {
            $this->deletePublicFile($university->logo_path);
            $path = $this->storePublicFile($request, 'logo', 'universities/logos');
            if ($path) {
                $data['logo_path'] = $path;
            }
        }

        unset($data['logo']);
        $university->update($data);

        return response()->json($university->fresh());
    }

    public function destroy(University $university): JsonResponse
    {
        $this->deletePublicFile($university->logo_path);
        $university->delete();

        return response()->json(null, 204);
    }
}
