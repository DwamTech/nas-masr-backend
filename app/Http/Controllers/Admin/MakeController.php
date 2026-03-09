<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Listing;
use App\Models\Make;
use App\Models\CarModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;


class MakeController extends Controller
{
    public function index()
    {
        // Get makes with their models, sorted by rank
        $items = Make::with(['models' => function ($query) {
            // Sort models by rank (from category_field_option_ranks table)
            $query->leftJoin('category_field_option_ranks', function ($join) {
                $join->on('models.name', '=', 'category_field_option_ranks.option_value')
                     ->where('category_field_option_ranks.field_name', '=', 'model')
                     ->whereExists(function ($query) {
                         $query->select(\DB::raw(1))
                               ->from('categories')
                               ->whereColumn('categories.id', 'category_field_option_ranks.category_id')
                               ->where('categories.slug', 'cars');
                     });
            })
            ->select('models.*')
            ->orderByRaw('COALESCE(category_field_option_ranks.rank, 999999) ASC');
        }])
        // Sort makes by rank
        ->leftJoin('category_field_option_ranks', function ($join) {
            $join->on('makes.name', '=', 'category_field_option_ranks.option_value')
                 ->where('category_field_option_ranks.field_name', '=', 'brand')
                 ->whereExists(function ($query) {
                     $query->select(\DB::raw(1))
                           ->from('categories')
                           ->whereColumn('categories.id', 'category_field_option_ranks.category_id')
                           ->where('categories.slug', 'cars');
                 });
        })
        ->select('makes.*')
        ->orderByRaw('COALESCE(category_field_option_ranks.rank, 999999) ASC')
        ->get();
        
        // معالجة الماركات والموديلات (الترتيب سيتم في الفرونت إند)
        $makesArray = [];
        foreach ($items as $make) {
            $modelNames = $make->models->pluck('name')->toArray();
            // لا نعيد الترتيب - نحافظ على ترتيب الـ query
            
            $makesArray[] = [
                'id' => $make->id,
                'name' => $make->name,
                'models' => collect($modelNames)->map(function($modelName) use ($make) {
                    return (object)[
                        'name' => $modelName,
                        'make_id' => $make->id
                    ];
                })->all()
            ];
        }
        
        // إضافة "غير ذلك" في الآخر
        $makesArray[] = [
            'id' => null,
            'name' => 'غير ذلك',
            'models' => []
        ];
        
        // تحويل إلى objects
        $result = collect($makesArray)->map(function($item) {
            return (object)$item;
        });
        
        return response()->json($result);
    }

    public function addMake(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'], // شيلنا unique هنا
            // 'models'   => ['nullable', 'array'],
            // 'models.*' => ['string', 'max:191'],
        ]);

        $make = Make::where('name', $data['name'])->first();

        $isNew = false;

        if (!$make) {
            $make = Make::create(['name' => $data['name']]);
            
            // إضافة موديل "غير ذلك" تلقائيًا
            CarModel::create([
                'name' => 'غير ذلك',
                'make_id' => $make->id,
            ]);

            $isNew = true;
        } else {
            return response()->json([
                'message' => 'Make with this name already exists.',
            ], 422);
        }

        // // 2) نجهز الموديلات اللي جاية من الريكوست
        // $models = collect($data['models'] ?? [])
        //     ->map(fn($m) => trim($m))
        //     ->filter()          // شيل الفاضي
        //     ->unique()          // شيل التكرار
        //     ->values();         // ريسيت للـ index

        // $existing = $make->models()
        //     ->pluck('id', 'name');

        // $keepIds = [];

        // foreach ($models as $modelName) {
        //     if (isset($existing[$modelName])) {
        //         // الموديل موجود بالفعل بنفس الاسم → نخليه
        //         $keepIds[] = $existing[$modelName];
        //     } else {
        //         // موديل جديد → نضيفه
        //         $model = $make->models()->create([
        //             'name' => $modelName,
        //         ]);
        //         $keepIds[] = $model->id;
        //     }
        // }


        // if (count($keepIds) > 0) {
        //     $make->models()
        //         ->whereNotIn('id', $keepIds)
        //         ->delete();
        // } else {
        //     // لو مبعتيش ولا موديل → امسح كل الموديلات القديمة
        //     $make->models()->delete();
        // }


        $make->load('models');

        return response()->json($make, $isNew ? 201 : 200);
    }

    public function update(Request $request, Make $make)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:191', 'unique:makes,name,' . $make->id],
        ]);

        if (array_key_exists('name', $data)) {
            $make->update(['name' => $data['name']]);
        }

        return response()->json($make->load('models'));
    }

    public function destroy(Make $make)
    {
        // كل الموديلات اللي تحت الماركة دي
        $modelIds = $make->models()->pluck('id');

        $isUsed = Listing::where('make_id', $make->id)
            ->orWhereIn('model_id', $modelIds)
            ->exists();

        if ($isUsed) {
            return response()->json([
                'message' => 'لا يمكن حذف هذه الماركة لأنها مرتبطة بإعلانات أو موديلات مستخدمة في إعلانات.',
            ], 422);
        }

        // لو حابة كمان يتم حذف كل الموديلات التابعة ليها:
        $make->models()->delete();

        $make->delete();

        return response()->json("Deleted successfully", 204);
    }

    public function models(Make $make)
    {
        $models = $make->models()->get();
        
        // معالجة الموديلات (الترتيب سيتم في الفرونت إند)
        $modelNames = $models->pluck('name')->toArray();
        $modelNames = \App\Support\OptionsHelper::processOptions($modelNames, false, false);
        
        // تحويل إلى objects مع الحفاظ على الترتيب
        $sortedModels = collect($modelNames)->map(function($name) use ($models, $make) {
            $model = $models->firstWhere('name', $name);
            return (object)[
                'id' => $model ? $model->id : null,
                'name' => $name,
                'make_id' => $make->id
            ];
        });
        
        return response()->json($sortedModels);
    }

    public function addModel(Request $request, Make $make)
    {
        // ✅ Validate
        $data = $request->validate([
            'models' => ['required', 'array', 'min:1'],
            'models.*' => [
                'required',
                'string',
                'max:191',
                // Unique per make
                Rule::unique('models', 'name')->where(function ($q) use ($make) {
                    return $q->where('make_id', $make->id);
                }),
            ],
        ]);

        $createdModels = [];

        // ✅ Create all models
        foreach ($data['models'] as $name) {
            $createdModels[] = CarModel::create([
                'name' => $name,
                'make_id' => $make->id,
            ]);
        }

        // ✅ Response بالشكل اللي تحبيه
        return response()->json([
            'make_id' => $make->id,
            'models' => $createdModels,
        ], 201);
    }

    public function updateModel(Request $request, CarModel $model)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:191', 'unique:models,name,' . $model->id . ',id,make_id,' . $model->make_id],
            'make_id' => ['required', 'integer', 'exists:makes,id'],
        ]);

        $model->update($data);
        return response()->json($model);
    }

    public function deleteModel(CarModel $model)
    {
        $isUsed = Listing::where('model_id', $model->id)->exists();

        if ($isUsed) {
            return response()->json([
                'message' => 'لا يمكن حذف هذا الموديل لأنه مستخدم في إعلانات حالية.',
            ], 422);
        }

        $model->delete();

        return response()->json("Deleted successfully", 204);
    }
}
