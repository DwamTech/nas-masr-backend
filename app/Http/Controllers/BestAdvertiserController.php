<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\BestAdvertiser;
use App\Models\Category;
use App\Models\Listing;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Support\Section;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;



class BestAdvertiserController extends Controller
{

    public function index(string $section)
    {
        Listing::autoExpire();
        $sec = Section::fromSlug($section);
        $categoryId = $sec->id();

        $featured = BestAdvertiser::active()
            ->whereRaw('JSON_CONTAINS(category_ids, ?)', [json_encode((int) $categoryId)])
            ->with('user')
            // ->orderBy('rank')
            ->get();

        $userIds = $featured->pluck('user_id')->map(fn($v) => (int)$v)->all();

        if (count($userIds) === 0) {
            return response()->json(['advertisers' => []]);
        }

        $idsStr = implode(',', $userIds);

        // Get max listings count from settings, default to 8
        $maxListings = $this->safeRemember('settings:featured_user_max_ads', now()->addHours(6), function () {
            return (int) (SystemSetting::where('key', 'featured_user_max_ads')->value('value') ?? 8);
        });

        // Get listings per user ordered by rank first
        $rows = DB::select("
        SELECT id, user_id
        FROM (
            SELECT l.*,
                ROW_NUMBER() OVER (
                    PARTITION BY user_id
                    ORDER BY l.rank ASC, l.published_at DESC, l.created_at DESC
                ) rn
            FROM listings l
            WHERE l.category_id = ?
                AND l.status = 'Valid'
                AND l.user_id IN ($idsStr)
        ) t
        WHERE rn <= ?
    ", [(int)$categoryId, $maxListings]);

        // Collect listing IDs so we can eager load them
        $listingIds = collect($rows)->pluck('id')->all();

        // eager load
        $listings = Listing::with([
            'attributes',
            'make',
            'model',
            'governorate',
            'city',
        ])->whereIn('id', $listingIds)->get()->keyBy('id');

        // Group by user — Minimal + attributes + price + category fields
        $byUser = [];
        foreach ($rows as $row) {
            $listing = $listings[$row->id] ?? null;
            if ($listing) {
                // attributes بالكامل (EAV)
                $attrs = [];
                if ($listing->relationLoaded('attributes')) {
                    foreach ($listing->attributes as $attr) {
                        $attrs[$attr->key] = $this->castEavValueRow($attr);
                    }
                }

                // أسماء المحافظة/المدينة
                $govName  = ($listing->relationLoaded('governorate') && $listing->governorate) ? $listing->governorate->name : null;
                $cityName = ($listing->relationLoaded('city') && $listing->city) ? $listing->city->name : null;

                // القسم الحالي لهذا الإعلان
                $lSec = $listing->category_id ? Section::fromId($listing->category_id) : null;
                $catSlug = $lSec?->slug ?? null;
                $catName = $lSec?->name ?? null;
                
                // Get category model for unified image fields
                $category = $listing->category_id ? Category::find($listing->category_id) : null;

                $byUser[$row->user_id][] = [
                    'main_image_url' => ($section === 'jobs' || $section === 'doctors' || $section === 'teachers')
                        ? (asset('storage/' . $this->safeRemember("settings:{$section}_default_image", now()->addHours(6), fn() => \App\Models\SystemSetting::where('key', "{$section}_default_image")->value('value') ?? "defaults/{$section}_default.png")))
                        : ($listing->main_image ? asset('storage/' . $listing->main_image) : null),
                    'governorate'    => $govName,
                    'city'           => $cityName,
                    'price'          => $listing->price,
                    'attributes'     => $attrs,
                    'rank'           => $listing->rank,
                    'views'          => $listing->views,
                    'id' => $listing->id,
                    'lat' => $listing->lat,
                    'lng' => $listing->lng,

                    // ✅ الكاتيجري بالإنجليزي (slug) وبالعربي (name)
                    'category'       => $catSlug,
                    'category_name'  => $catName,
                    
                    // Unified category image fields
                    'is_global_image_active' => $category->is_global_image_active ?? false,
                    'global_image_url' => $category->global_image_url,
                    'global_image_full_url' => $category->global_image_full_url,
                ];
            }
        }

        // Build output
        $out = $featured->map(function (BestAdvertiser $ba) use ($byUser) {
            $u = $ba->user;

            return [
                'id' => $ba->id,
                'user' => [
                    'name' => $u->name,
                    'id' => $u->id,
                ],
                'listings' => $byUser[$ba->user_id] ?? [],
            ];
        })->values();

        return response()->json(['advertisers' => $out]);
    }

    protected function castEavValueRow($attr)
    {
        return $attr->value_int
            ?? $attr->value_decimal
            ?? $attr->value_bool
            ?? $attr->value_string
            ?? $this->decodeJsonSafe($attr->value_json)
            ?? $attr->value_date
            ?? null;
    }

    protected function decodeJsonSafe($json)
    {
        if (is_null($json)) return null;
        if (is_array($json)) return $json;

        $x = json_decode($json, true);
        return json_last_error() === JSON_ERROR_NONE ? $x : $json;
    }



    //--------------------------------------------Admin Endpoints

    public function store(Request $request)
    {
        $data = $request->validate([
            'user_id'        => ['required', 'integer', 'exists:users,id'],
            'category_ids'   => ['required', 'array'],
            'category_ids.*' => ['integer'],
            'max_listings'   => ['nullable', 'integer'],
            'is_active'      => ['boolean'],
        ]);

        $data['is_active'] = $data['is_active'] ?? true;

        $user = User::find($data['user_id']);
        if (!$user || $user->status !== 'active') {
            return response()->json(['message' => 'User must be active'], 422);
        }

        $existingCategoryIds = Category::whereIn('id', $data['category_ids'])->pluck('id')->all();
        $invalidIds = array_diff($data['category_ids'], $existingCategoryIds);
        if (!empty($invalidIds)) {
            return response()->json([
                'message'     => 'Some categories do not exist',
                'invalid_ids' => $invalidIds
            ], 422);
        }

        $limit = $this->safeRememberForever('settings:featured_users_count', function () {
            return (int) (SystemSetting::where('key', 'featured_users_count')->value('value') ?? 8);
        });

        [$ba, $message] = DB::transaction(function () use ($data, $limit) {

            $ba = BestAdvertiser::where('user_id', $data['user_id'])->lockForUpdate()->first();

            $wasActive    = (bool) ($ba->is_active ?? false);
            $willBeActive = (bool) $data['is_active'];

            $activeCountQuery = BestAdvertiser::where('is_active', true);
            if ($ba && $wasActive) {
                $activeCountQuery->where('user_id', '!=', $ba->user_id);
            }
            $activeCount = (int) $activeCountQuery->lockForUpdate()->count();

            if ((!$wasActive && $willBeActive) || (!$ba && $willBeActive)) {
                if ($activeCount >= $limit) {
                    throw ValidationException::withMessages([
                        'limit' => "تم بلوغ الحد الأقصى للمستخدمين المميزين ({$limit})."
                    ]);
                }
            }

            if ($ba) {
                $ba->update($data);
                $message = 'Best advertiser updated';
            } else {
                $ba = BestAdvertiser::create($data);
                $message = 'Best advertiser created';
            }

            return [$ba, $message];
        });

        $categories = Category::whereIn('id', $ba->category_ids)->get(['id', 'name', 'slug']);

        return response()->json([
            'message' => $message,
            'data'    => [
                'best_advertiser' => $ba,
                'categories'      => $categories,
            ]
        ], $ba->wasRecentlyCreated ? 201 : 200);
    }
    public function show($userId)
    {
        $bestAdvertiser = BestAdvertiser::where('user_id', $userId)->first();

        if (!$bestAdvertiser) {
            return response()->json([
                'message' => 'Best advertiser not found',
                'data' => null
            ], 404);
        }

        $categories = Category::whereIn('id', $bestAdvertiser->category_ids)->get(['id', 'name', 'slug']);

        return response()->json([
            'data' => [
                'id' => $bestAdvertiser->id,
                'user_id' => $bestAdvertiser->user_id,
                'category_ids' => $bestAdvertiser->category_ids,
                'is_active' => $bestAdvertiser->is_active,
                'categories' => $categories,
            ]
        ]);
    }





    // ADMIN: تعطيل مستخدم مميز
    public function disable(BestAdvertiser $bestAdvertiser)
    {
        $bestAdvertiser->update(['is_active' => false]);
        return response()->json(['message' => 'Best advertiser disabled']);
    }

    // ADMIN: حذف مستخدم مميز
    // public function destroy(BestAdvertiser $bestAdvertiser)
    // {
    //     $bestAdvertiser->delete();
    //     return response()->json(['message' => 'Best advertiser deleted']);
    // }

    private function safeRemember(string $key, $ttl, callable $resolver)
    {
        try {
            return Cache::remember($key, $ttl, $resolver);
        } catch (\Throwable $e) {
            Log::warning('cache_fallback_in_best_advertiser', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);
            return $resolver();
        }
    }

    private function safeRememberForever(string $key, callable $resolver)
    {
        try {
            return Cache::rememberForever($key, $resolver);
        } catch (\Throwable $e) {
            Log::warning('cache_fallback_in_best_advertiser_forever', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);
            return $resolver();
        }
    }
}
