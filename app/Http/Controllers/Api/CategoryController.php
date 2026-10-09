<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CategoryController extends Controller
{
    public function index(Request $request)
    {
        return $request->user()->categories()->orderBy('kind')->orderBy('name')->get();
    }

    public function store(Request $request)
    {
        return response()->json($request->user()->categories()->create($this->validated($request)), 201);
    }

    public function update(Request $request, int $id)
    {
        $category = $request->user()->categories()->findOrFail($id);
        $category->update($this->validated($request, $id));

        return $category;
    }

    public function destroy(Request $request, int $id)
    {
        $request->user()->categories()->findOrFail($id)->delete();

        return response()->noContent();
    }

    private function validated(Request $request, ?int $id = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:50', Rule::unique('categories')->where('user_id', $request->user()->id)->ignore($id)],
            'icon' => 'nullable|string|max:16',
            'color' => 'nullable|string|max:9',
            'kind' => 'required|in:need,want,savings,income',
            'keywords' => 'nullable|array',
            'keywords.*' => 'string|max:40',
        ]);
    }
}
