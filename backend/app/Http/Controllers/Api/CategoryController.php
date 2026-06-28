<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CategoryController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $query = Category::query()->whereIn('store_id', auth()->user()->getAccessibleStoreIds());

        if ($search = request('search')) {
            $query->where('name', 'like', "%{$search}%");
        }

        $perPage = (int) request('per_page', 50);
        $perPage = max(1, min(200, $perPage));

        return $query->latest()->paginate($perPage);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $storeId = auth()->user()->store_id;
        if (auth()->user()->role === 'owner' && !$storeId) {
            return response()->json(['message' => 'Please select a specific store first to create a category.'], 400);
        }

        return Category::create([
            'store_id' => $storeId,
            'name' => $request->validate([
                'name' => [
                    'required',
                    'string',
                    'max:100',
                    Rule::unique('categories', 'name')->where(fn ($q) => $q->where('store_id', $storeId)),
                ],
            ])['name'],
        ]);
    }

    /**
     * Display the specified resource.
     */
    public function show(Category $category)
    {
        abort_unless(in_array($category->store_id, auth()->user()->getAccessibleStoreIds()), 404);
        return $category;
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Category $category)
    {
        abort_unless(in_array($category->store_id, auth()->user()->getAccessibleStoreIds()), 404);

        $storeId = $category->store_id;
        $category->update($request->validate([
            'name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('categories', 'name')
                    ->where(fn ($q) => $q->where('store_id', $storeId))
                    ->ignore($category->id),
            ],
        ]));

        return $category;
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Category $category)
    {
        abort_unless(in_array($category->store_id, auth()->user()->getAccessibleStoreIds()), 404);
        $category->delete();
        return response()->json(['message' => 'Deleted']);
    }
}
