<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\BestAdvertiser;
use App\Models\BestAdvertiserSectionRank;
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
    public function sectionsIndex()
    {
        $categories = Category::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $counts = [];

        BestAdvertiser::query()
            ->with('user:id,status')
            ->get()
            ->filter(fn (BestAdvertiser $advertiser) => $this->isActiveFeaturedAdvertiser($advertiser))
            ->each(function (BestAdvertiser $advertiser) use (&$counts) {
                foreach ($this->normalizedCategoryIds($advertiser->category_ids) as $categoryId) {
                    $counts[$categoryId] = ($counts[$categoryId] ?? 0) + 1;
                }
            });

        return response()->json([
            'sections' => $categories->map(function (Category $category) use ($counts) {
                return $this->formatSectionSummary($category, (int) ($counts[$category->id] ?? 0));
            })->values(),
        ]);
    }

    public function sectionAdvertisers(string $slug)
    {
        $category = Category::query()
            ->where('slug', $slug)
            ->first();

        if (!$category) {
            return response()->json([
                'message' => 'القسم غير موجود',
            ], 404);
        }

        $categories = Category::query()
            ->whereIn('id', $this->collectActiveFeaturedCategoryIds())
            ->get(['id', 'name', 'slug'])
            ->keyBy('id');

        $sectionRanks = $this->getSectionRanksMap($category->id);

        $advertisers = BestAdvertiser::query()
            ->with([
                'user:id,name,phone,status,profile_image',
                'sectionRanks' => fn ($query) => $query
                    ->where('category_id', $category->id)
                    ->select('id', 'best_advertiser_id', 'category_id', 'rank'),
            ])
            ->orderBy('id')
            ->get()
            ->filter(fn (BestAdvertiser $advertiser) => $this->belongsToActiveSection($advertiser, $category->id))
            ->values()
            ->sortBy(fn (BestAdvertiser $advertiser) => $this->resolveAdvertiserSectionRank($advertiser, $category->id, $sectionRanks))
            ->values();

        $maxVisibleListings = $this->safeRemember('settings:featured_user_max_ads', now()->addHours(6), function () {
            return (int) (SystemSetting::where('key', 'featured_user_max_ads')->value('value') ?? 8);
        });

        $visibleListingsCountByUser = Listing::query()
            ->selectRaw('user_id, COUNT(*) as aggregate')
            ->where('category_id', $category->id)
            ->where('status', 'Valid')
            ->whereIn('user_id', $advertisers->pluck('user_id')->all())
            ->groupBy('user_id')
            ->pluck('aggregate', 'user_id')
            ->map(fn ($count) => min((int) $count, $maxVisibleListings))
            ->all();

        $advertisers = $advertisers
            ->map(function (BestAdvertiser $advertiser) use ($categories, $category, $sectionRanks, $visibleListingsCountByUser) {
                $sectionCategories = collect($this->normalizedCategoryIds($advertiser->category_ids))
                    ->map(fn (int $categoryId) => $categories->get($categoryId))
                    ->filter()
                    ->values()
                    ->map(fn (Category $sectionCategory) => [
                        'id' => $sectionCategory->id,
                        'name' => $sectionCategory->name,
                        'slug' => $sectionCategory->slug,
                    ])
                    ->all();

                return [
                    'id' => $advertiser->id,
                    'user_id' => $advertiser->user_id,
                    'name' => (string) ($advertiser->user?->name ?? 'معلن بدون اسم'),
                    'phone' => (string) ($advertiser->user?->phone ?? ''),
                    'profile_image_url' => $advertiser->user?->profile_image_url,
                    'rank' => (int) $this->resolveAdvertiserSectionRank($advertiser, $category->id, $sectionRanks),
                    'max_listings' => (int) ($advertiser->max_listings ?? 0),
                    'current_section_visible_listings_count' => (int) ($visibleListingsCountByUser[$advertiser->user_id] ?? 0),
                    'has_visible_listings_in_section' => (int) ($visibleListingsCountByUser[$advertiser->user_id] ?? 0) > 0,
                    'categories_count' => count($sectionCategories),
                    'categories' => $sectionCategories,
                ];
            })
            ->values();

        return response()->json([
            'section' => $this->formatSectionSummary($category, $advertisers->count()),
            'advertisers' => $advertisers,
        ]);
    }

    public function reorderSectionAdvertisers(Request $request, string $slug)
    {
        $validated = $request->validate([
            'advertiser_ids' => ['required', 'array', 'min:1'],
            'advertiser_ids.*' => ['required', 'integer', 'distinct', 'exists:best_advertiser,id'],
        ]);

        $category = Category::query()
            ->where('slug', $slug)
            ->first();

        if (!$category) {
            return response()->json([
                'success' => false,
                'message' => 'القسم غير موجود',
            ], 404);
        }

        $updatedCount = DB::transaction(function () use ($validated, $category) {
            $sectionAdvertisers = BestAdvertiser::query()
                ->with(['user:id,status', 'sectionRanks' => fn ($query) => $query
                    ->where('category_id', $category->id)
                    ->select('id', 'best_advertiser_id', 'category_id', 'rank')])
                ->lockForUpdate()
                ->get()
                ->filter(fn (BestAdvertiser $advertiser) => $this->belongsToActiveSection($advertiser, $category->id))
                ->values();

            if ($sectionAdvertisers->isEmpty()) {
                throw ValidationException::withMessages([
                    'advertiser_ids' => 'لا يوجد معلنون مميزون في هذا القسم لإعادة ترتيبهم.',
                ]);
            }

            $requestedIds = collect($validated['advertiser_ids'])
                ->map(fn ($id) => (int) $id)
                ->values();

            $expectedIds = $sectionAdvertisers
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->values();

            if (
                $requestedIds->count() !== $expectedIds->count()
                || $requestedIds->diff($expectedIds)->isNotEmpty()
                || $expectedIds->diff($requestedIds)->isNotEmpty()
            ) {
                throw ValidationException::withMessages([
                    'advertiser_ids' => 'يجب إرسال جميع معلني القسم مرة واحدة لإعادة ترتيبهم.',
                ]);
            }

            foreach ($requestedIds as $index => $advertiserId) {
                BestAdvertiserSectionRank::updateOrCreate(
                    [
                        'best_advertiser_id' => $advertiserId,
                        'category_id' => $category->id,
                    ],
                    [
                        'rank' => $index + 1,
                    ]
                );
            }

            return $sectionAdvertisers->count();
        });

        return response()->json([
            'success' => true,
            'message' => 'تم تحديث ترتيب المعلنين المميزين بنجاح',
            'data' => [
                'updated_count' => $updatedCount,
                'section' => $slug,
            ],
        ]);
    }

    public function index(string $section)
    {
        Listing::autoExpire();
        $sec = Section::fromSlug($section);
        $categoryId = $sec->id();
        $category = Category::query()->find($categoryId);

        if ($category && !$category->show_featured_advertisers) {
            return response()->json([
                'advertisers' => [],
                'show_featured_advertisers' => false,
            ]);
        }

        $featured = BestAdvertiser::active()
            ->with([
                'user',
                'sectionRanks' => fn ($query) => $query
                    ->where('category_id', $categoryId)
                    ->select('id', 'best_advertiser_id', 'category_id', 'rank'),
            ])
            ->get()
            ->filter(fn (BestAdvertiser $advertiser) => in_array(
                $categoryId,
                $this->normalizedCategoryIds($advertiser->category_ids),
                true
            ))
            ->values();

        $sectionRanks = $this->getSectionRanksMap($categoryId);
        $featured = $featured
            ->sortBy(fn (BestAdvertiser $advertiser) => $this->resolveAdvertiserSectionRank($advertiser, $categoryId, $sectionRanks))
            ->values();

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
            'rank'           => ['nullable', 'integer', 'min:0'],
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

        $normalizedCategoryIds = $this->normalizedCategoryIds($data['category_ids']);
        $data['category_ids'] = $normalizedCategoryIds;

        [$ba, $message] = DB::transaction(function () use ($data, $limit, $normalizedCategoryIds) {

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

            $payload = $data;

            if (!$ba && !array_key_exists('rank', $payload)) {
                $payload['rank'] = ((int) BestAdvertiser::query()->max('rank')) + 1;
            }

            if ($ba && (!array_key_exists('rank', $payload) || $payload['rank'] === null)) {
                unset($payload['rank']);
            }

            if ($ba) {
                $ba->update($payload);
                $message = 'Best advertiser updated';
            } else {
                $ba = BestAdvertiser::create($payload);
                $message = 'Best advertiser created';
            }

            $this->syncSectionRanks($ba, $normalizedCategoryIds);

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
                'max_listings' => $bestAdvertiser->max_listings,
                'rank' => $bestAdvertiser->rank,
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

    private function formatSectionSummary(Category $category, int $featuredAdvertisersCount): array
    {
        return [
            'id' => $category->id,
            'slug' => $category->slug,
            'name' => $category->name,
            'icon' => $category->icon,
            'icon_url' => $category->icon ? asset('storage/uploads/categories/' . $category->icon) : null,
            'global_image_url' => $category->global_image_url,
            'global_image_full_url' => $category->global_image_full_url,
            'show_featured_advertisers' => (bool) ($category->show_featured_advertisers ?? true),
            'featured_advertisers_count' => $featuredAdvertisersCount,
        ];
    }

    private function normalizedCategoryIds($categoryIds): array
    {
        if (!is_array($categoryIds)) {
            return [];
        }

        return array_values(array_unique(array_map('intval', array_filter($categoryIds, fn ($value) => is_numeric($value)))));
    }

    private function isActiveFeaturedAdvertiser(BestAdvertiser $advertiser): bool
    {
        return (bool) $advertiser->is_active
            && $advertiser->relationLoaded('user')
            && $advertiser->user
            && $advertiser->user->status === 'active';
    }

    private function belongsToActiveSection(BestAdvertiser $advertiser, int $categoryId): bool
    {
        return $this->isActiveFeaturedAdvertiser($advertiser)
            && in_array($categoryId, $this->normalizedCategoryIds($advertiser->category_ids), true);
    }

    private function getSectionRanksMap(int $categoryId): array
    {
        return BestAdvertiserSectionRank::query()
            ->where('category_id', $categoryId)
            ->pluck('rank', 'best_advertiser_id')
            ->map(fn ($rank) => (int) $rank)
            ->all();
    }

    private function resolveAdvertiserSectionRank(BestAdvertiser $advertiser, int $categoryId, array $sectionRanks = []): int
    {
        if (isset($sectionRanks[$advertiser->id])) {
            return (int) $sectionRanks[$advertiser->id];
        }

        if ($advertiser->relationLoaded('sectionRanks')) {
            $existingRank = $advertiser->sectionRanks->firstWhere('category_id', $categoryId);
            if ($existingRank) {
                return (int) $existingRank->rank;
            }
        }

        return (int) ($advertiser->rank > 0 ? $advertiser->rank : 1000000 + (int) $advertiser->id);
    }

    private function syncSectionRanks(BestAdvertiser $bestAdvertiser, array $categoryIds): void
    {
        $normalizedCategoryIds = $this->normalizedCategoryIds($categoryIds);

        if (empty($normalizedCategoryIds)) {
            BestAdvertiserSectionRank::query()
                ->where('best_advertiser_id', $bestAdvertiser->id)
                ->delete();

            return;
        }

        BestAdvertiserSectionRank::query()
            ->where('best_advertiser_id', $bestAdvertiser->id)
            ->whereNotIn('category_id', $normalizedCategoryIds)
            ->delete();

        $existingCategoryIds = BestAdvertiserSectionRank::query()
            ->where('best_advertiser_id', $bestAdvertiser->id)
            ->pluck('category_id')
            ->map(fn ($categoryId) => (int) $categoryId)
            ->all();

        foreach ($normalizedCategoryIds as $categoryId) {
            if (in_array($categoryId, $existingCategoryIds, true)) {
                continue;
            }

            $nextRank = (int) BestAdvertiserSectionRank::query()
                ->where('category_id', $categoryId)
                ->max('rank');

            BestAdvertiserSectionRank::create([
                'best_advertiser_id' => $bestAdvertiser->id,
                'category_id' => $categoryId,
                'rank' => $nextRank + 1,
            ]);
        }
    }

    private function collectActiveFeaturedCategoryIds(): array
    {
        $categoryIds = [];

        BestAdvertiser::query()
            ->with('user:id,status')
            ->get()
            ->filter(fn (BestAdvertiser $advertiser) => $this->isActiveFeaturedAdvertiser($advertiser))
            ->each(function (BestAdvertiser $advertiser) use (&$categoryIds) {
                $categoryIds = array_merge($categoryIds, $this->normalizedCategoryIds($advertiser->category_ids));
            });

        return array_values(array_unique($categoryIds));
    }
}
