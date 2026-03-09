<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\CategoryMainSection;
use App\Models\CategorySubSection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Listing;
use Illuminate\Validation\Rule;


class CategorySectionsController extends Controller
{

    public function index(Request $request)
    {
        $slug = $request->query('category_slug');

        if (!$slug) {
            return response()->json([
                'message' => 'يجب تحديد القسم بواسطة باراميتر category_slug.',
            ], 422);
        }

        $category = Category::where('slug', $slug)->first();

        if (!$category) {
            return response()->json([
                'message' => 'القسم غير موجود.',
            ], 404);
        }

        $mainSections = CategoryMainSection::with([
            'subSections' => function ($q) {
                $q->where('is_active', true)
                    ->orderBy('sort_order');
            }
        ])
            ->where('category_id', $category->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        $mainSections->push((object)[
            'id' => null,
            'name' => 'غير ذلك',
            'subSections' => [],
            'sort_order' => 9999,
            'category_id' => $category->id
        ]);

        return response()->json([
            'category' => [
                'id' => $category->id,
                'slug' => $category->slug,
                'name' => $category->name,
            ],
            'main_sections' => $mainSections,
        ]);
    }

    /**
     * POST /api/admin/category-sections/{category_slug}
     *
     * body:
     * {
     *   "main_sections": [
     *     {
     *       "id": 1,                    // اختياري (للتعديل)
     *       "name": "ملابس رجالي كاجوال",
     *       "title": "عنوان اختياري",   // اختياري
     *       "sort_order": 1,            // اختياري
     *       "is_active": true,          // اختياري
     *       "sub_sections": [
     *         {
     *           "id": 10,               // اختياري (للتعديل)
     *           "name": "تيشيرت",
     *           "title": "عنوان اختياري", // اختياري
     *           "sort_order": 1,        // اختياري
     *           "is_active": true       // اختياري
     *         }
     *       ]
     *     }
     *   ]
     * }
     */
    // POST /api/admin/category-sections/{category_slug}/main

    public function subSections(CategoryMainSection $mainSection)
    {
        $subSections = $mainSection->subSections()->orderBy('sort_order')->get();
        $subSections->push((object)[
            'id' => null,
            'name' => 'غير ذلك',
            'main_section_id' => $mainSection->id,
            'category_id' => $mainSection->category_id
        ]);
        return response()->json($subSections);
    }
    public function storeMain(Request $request, string $categorySlug)
    {
        $category = Category::where('slug', $categorySlug)->firstOrFail();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'title' => ['nullable', 'string', 'max:255'],
        ]);

        $main = CategoryMainSection::where('category_id', $category->id)
            ->where('name', $data['name'])
            ->first();

        $isNew = false;

        if (!$main) {
            $main = CategoryMainSection::create([
                'category_id' => $category->id,
                'name'        => $data['name'],
                'title'       => $data['title'] ?? null,
                'sort_order'  => (CategoryMainSection::where('category_id', $category->id)->max('sort_order') ?? 0) + 1,
                'is_active'   => true,
            ]);

            // إضافة "غير ذلك" كقسم فرعي تلقائيًا
            CategorySubSection::create([
                'category_id'     => $category->id,
                'main_section_id' => $main->id,
                'name'            => 'غير ذلك',
                'sort_order'      => 9999,
                'is_active'       => true,
            ]);

            $isNew = true;
        } else {
            return response()->json([
                'message' => 'قسم رئيسي بهذا الاسم موجود بالفعل لهذا القسم.',
            ], 422);
        }

        // رجّع القسم مع الأقسام الفرعية بتاعته (لو فيه)
        $main->load(['subSections' => function ($q) {
            $q->where('is_active', true)->orderBy('sort_order');
        }]);

        return response()->json($main, $isNew ? 201 : 200);
    }


    public function addSubSections(Request $request, CategoryMainSection $mainSection)
    {
        $data = $request->validate([
            'sub_sections'   => ['required', 'array', 'min:1'],
            'sub_sections.*' => [
                'required',
                'string',
                'max:191',
                Rule::unique('category_sub_section', 'name')->where(function ($q) use ($mainSection) {
                    return $q->where('category_id', $mainSection->category_id)
                        ->where('main_section_id', $mainSection->id);
                }),
            ],
        ]);

        $created = [];
        $sortBase = (int) CategorySubSection::where('category_id', $mainSection->category_id)
            ->where('main_section_id', $mainSection->id)
            ->max('sort_order');

        $order = $sortBase + 1;

        foreach ($data['sub_sections'] as $name) {
            $created[] = CategorySubSection::create([
                'category_id'     => $mainSection->category_id,
                'main_section_id' => $mainSection->id,
                'name'            => $name,
                'sort_order'      => $order++,
                'is_active'       => true,
            ]);
        }

        return response()->json([
            'main_section_id' => $mainSection->id,
            'sub_sections'    => $created,
        ], 201);
    }


    // PUT /api/admin/category-sections/main/{mainSection}
    public function updateMain(Request $request, CategoryMainSection $mainSection)
    {
        $data = $request->validate([
            'name' => [
                'sometimes',
                'string',
                'max:191',
                Rule::unique('category_main_sections', 'name')
                    ->where(fn($q) => $q->where('category_id', $mainSection->category_id))
                    ->ignore($mainSection->id),
            ],
            'title' => [
                'nullable',
                'string',
                'max:255',
            ],
        ]);

        $mainSection->update($data);

        return response()->json(
            $mainSection->load('subSections')
        );
    }

    // DELETE /api/admin/category-sections/main/{mainSection}
    public function destroyMain(CategoryMainSection $mainSection)
    {
        $subIds = $mainSection->subSections()->pluck('id');

        $isUsed = Listing::where('main_section_id', $mainSection->id)
            ->orWhereIn('sub_section_id', $subIds)
            ->exists();

        if ($isUsed) {
            return response()->json([
                'message' => 'لا يمكن حذف هذا القسم الرئيسي لأنه مرتبط بإعلانات أو أقسام فرعية مستخدمة في إعلانات.',
            ], 422);
        }

        $mainSection->subSections()->delete();
        $mainSection->delete();

        return response()->json("Deleted successfully", 204);
    }

    // PUT /api/admin/category-sections/sub/{subSection}
    public function updateSub(Request $request, CategorySubSection $subSection)
    {
        $data = $request->validate([
            'name' => [
                'required',
                'string',
                'max:191',
                Rule::unique('category_sub_section', 'name')
                    ->where(
                        fn($q) => $q
                            ->where('category_id', $subSection->category_id)
                            ->where('main_section_id', $subSection->main_section_id)
                    )
                    ->ignore($subSection->id),
            ],
            'title' => [
                'nullable',
                'string',
                'max:255',
            ],
        ]);

        $subSection->update($data);

        return response()->json($subSection);
    }

    // DELETE /api/admin/category-sections/sub/{subSection}
    public function destroySub(CategorySubSection $subSection)
    {
        $isUsed = Listing::where('sub_section_id', $subSection->id)->exists();

        if ($isUsed) {
            return response()->json([
                'message' => 'لا يمكن حذف هذا القسم الفرعي لأنه مستخدم في إعلانات حالية.',
            ], 422);
        }

        $subSection->delete();

        return response()->json("Deleted successfully", 204);
    }

    // POST /api/admin/category-sections/main/ranks
    public function updateMainRanks(Request $request)
    {
        $data = $request->validate([
            'ranks' => 'required|array',
            'ranks.*.id' => 'required|integer|exists:category_main_sections,id',
            'ranks.*.rank' => 'required|integer|min:0',
        ]);

        foreach ($data['ranks'] as $item) {
            CategoryMainSection::where('id', $item['id'])->update(['sort_order' => $item['rank']]);
        }

        return response()->json(['message' => 'Ranks updated successfully']);
    }

    // POST /api/admin/category-sections/sub/ranks
    public function updateSubRanks(Request $request)
    {
        $data = $request->validate([
            'ranks' => 'required|array',
            'ranks.*.id' => 'required|integer|exists:category_sub_section,id',
            'ranks.*.rank' => 'required|integer|min:0',
        ]);

        foreach ($data['ranks'] as $item) {
            CategorySubSection::where('id', $item['id'])->update(['sort_order' => $item['rank']]);
        }

        return response()->json(['message' => 'Ranks updated successfully']);
    }
}
