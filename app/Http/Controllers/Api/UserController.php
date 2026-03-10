<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ListingResource;
use App\Models\Listing;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\UserClient;
use App\Models\UserPackages;
use App\Models\UserPlanSubscription;
use App\Support\Section;
use App\Traits\HasRank;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;


use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Support\Carbon;
use App\Models\CategoryPlanPrice;
use App\Models\ListingPayment;
use App\Services\NotificationService;


class UserController extends Controller
{
    //
    use HasRank;

    public function getUserProfile()
    {
        $user = Auth::user();
        $code = UserClient::where('user_id', $user->id)->first();

        if (!$user) {
            return response([
                'message' => 'User not authenticated'
            ], 401);
        }

        return response([
            'message' => 'Profile fetched successfully',
            'data' => $user,
            'code' => $code->user_id ?? null
        ], 200);
    }

    public function editProfile(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return response([
                'message' => 'User not authenticated'
            ], 401);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'phone' => ['sometimes', 'string', 'max:20', Rule::unique('users', 'phone')->ignore($user->id)],
            'password' => ['sometimes', 'string'],
            'lat' => ['sometimes', 'nullable', 'numeric'],
            'lng' => ['sometimes', 'nullable', 'numeric'],
            'referral_code' => ['sometimes', 'nullable', 'string'],
            'address' => ['sometimes', 'nullable', 'string'],
        ]);

        if (!empty($validated['referral_code'])) {
            // Don't allow changing referral_code if already set
            if (!empty($user->referral_code) && $user->referral_code !== $validated['referral_code']) {
                return response([
                    'message' => 'You cannot change your delegate code once it has been set.'
                ], 422);
            }

            // Check if referral_code is a valid user ID who is a representative
            // UPDATED: Now checks is_representative flag instead of role
            $delegateUser = User::where('id', $validated['referral_code'])
                ->where('is_representative', true)
                ->first();

            if (!$delegateUser) {
                return response([
                    'message' => 'Invalid delegate code. Please check the code and try again.'
                ], 404);
            }

            // Add user to delegate's clients list
            $userClient = UserClient::firstOrCreate(
                ['user_id' => $validated['referral_code']],
                ['clients' => []]
            );

            $clients = $userClient->clients ?? [];

            // Check if user is already in the list
            if (!in_array($user->id, $clients)) {
                $clients[] = $user->id;
                $userClient->clients = $clients;
                $userClient->save();
            }
        }

        $user->update($validated);

        return response([
            'message' => 'Profile updated successfully',
            'data' => $user->fresh(),

        ], 200);
    }

    //my ads 
    public function myAds(Request $request)
    {
        Listing::autoExpire();
        $user = $request->user();
        $slug = $request->query('category_slug');
        $status = $request->query('status');
        $categoryId = null;
        // $supportsMakeModel = false;

        if ($slug) {
            $section = Section::fromSlug($slug);
            $categoryId = $section->id();
            // $supportsMakeModel = $section->supportsMakeModel();
        }

        // Build query
        $q = Listing::query()
            ->where('user_id', $user->id)
            // ->where('status', 'Valid')
            ->orderBy('rank', 'desc')
            ->orderBy('published_at', 'desc')
            ->orderBy('id', 'desc')
            ->with(['attributes', 'governorate', 'city', 'make', 'model', 'mainSection', 'subSection']);

        if ($categoryId) {
            $q->where('category_id', $categoryId);
        }
        if ($status) {
            $q->where('status', $status);
        }

        // if ($supportsMakeModel) {
        //     $q->with(['make', 'model']);
        // }

        $items = $q->get();

        return ListingResource::collection($items)
            ->additional([
                'category_slug' => $slug,
                // 'supports_make_model' => $supportsMakeModel,
            ]);
    }


    public function myPackages(Request $request)
    {
        $user = $request->user();
        $pkg = UserPackages::where('user_id', $user->id)->first();

        $makeCardLite = function (string $titleAr, bool $active, ?Carbon $expiresAt, ?int $days, int $total): array {
            return [
                'title' => $titleAr,
                'badge_text' => $active ? 'نشط' : 'منتهي',
                'expires_at_human' => $expiresAt?->translatedFormat('d/m/Y'),
                'note' => $expiresAt
                    ? ('تنتهي صلاحية الباقة بتاريخ ' . $expiresAt->translatedFormat('d/m/Y'))
                    : (
                        // لو الباقة بدون مدة (days = 0) وفيها رصيد، مفيش تاريخ انتهاء
                        ($days === 0 && $total > 0)
                        ? 'باقة بدون مدة — تعتمد على الرصيد فقط'
                        : null
                    ),
            ];
        };

        // لو مفيش record نهائي في user_packages للمستخدم
        if (!$pkg) {
            return response()->json([
                "message" => "عذراً، لا توجد أي باقة مفعّلة على حسابك حالياً. من فضلك قم بالاشتراك في باقة جديدة حتى تستطيع نشر أو تمييز الإعلانات."
            ], 422);
        }

        $packages = [];

        // ===== الباقة المتميزة =====
        $featuredActive = (bool) $pkg->featured_active;
        $featuredExp    = $pkg->featured_expire_date;         // Carbon|null
        $featuredDays   = (int) ($pkg->featured_days ?? 0);
        $featuredTotal  = (int) ($pkg->featured_ads ?? 0);

        // نعتبر إن الباقة دي "موجودة" في الجدول لو فيه أي حاجة منها مش فاضية
        $hasFeatured = $featuredDays > 0
            || $featuredTotal > 0
            || !is_null($featuredExp);

        if ($hasFeatured) {
            $packages[] = $makeCardLite(
                'متميز',
                $featuredActive,
                $featuredExp,
                $featuredDays,
                $featuredTotal
            );
        }

        // ===== الباقة القياسية =====
        $standardActive = (bool) $pkg->standard_active;
        $standardExp    = $pkg->standard_expire_date;
        $standardDays   = (int) ($pkg->standard_days ?? 0);
        $standardTotal  = (int) ($pkg->standard_ads ?? 0);

        $hasStandard = $standardDays > 0
            || $standardTotal > 0
            || !is_null($standardExp);

        if ($hasStandard) {
            $packages[] = $makeCardLite(
                'ستاندرد',
                $standardActive,
                $standardExp,
                $standardDays,
                $standardTotal
            );
        }

        // في الآخر نرجّع بس الباقات اللي المستخدم فعلاً دخل فيها قبل كده
        return response()->json([
            'packages' => $packages,
        ]);
    }

    public function myPlans(Request $request)
    {
        $user = $request->user();
        $slug = $request->query('category_slug');

        $subsQ = UserPlanSubscription::query()->where('user_id', $user->id);
        if ($slug) {
            $sec = Section::fromSlug($slug);
            $subsQ->where('category_id', $sec->id());
        }
        $subs = $subsQ->orderByDesc('id')->get();

        $subscriptions = $subs->map(function ($s) {
            $sec = Section::fromId((int) $s->category_id);
            $active = !$s->expires_at || $s->expires_at->isFuture();
            return [
                'id' => $s->id,
                'category_id' => (int) $s->category_id,
                'category_slug' => $sec?->slug,
                'category_name' => $sec?->name,
                'plan_type' => $s->plan_type,
                'days' => (int) ($s->days ?? 0),
                'subscribed_at' => $s->subscribed_at,
                'expires_at' => $s->expires_at,
                'price' => (float) ($s->price ?? 0),
                'ad_price' => (float) ($s->ad_price ?? 0),
                'ads_total' => (int) ($s->ads_total ?? 0),
                'ads_used' => (int) ($s->ads_used ?? 0),
                'remaining' => (int) $s->ads_remaining,
                'payment_status' => $s->payment_status,
                'payment_method' => $s->payment_method,
                'payment_reference' => $s->payment_reference,
                'active' => $active,
            ];
        })->values();

        $pkg = UserPackages::where('user_id', $user->id)->first();
        $packages = [];
        if ($pkg) {
            $packages[] = [
                'title' => 'متميز',
                'plan' => 'featured',
                'active' => (bool) $pkg->featured_active,
                'expires_at' => $pkg->featured_expire_date,
                'days' => (int) ($pkg->featured_days ?? 0),
                'total' => (int) ($pkg->featured_ads ?? 0),
                'used' => (int) ($pkg->featured_ads_used ?? 0),
                'remaining' => (int) $pkg->featured_ads_remaining,
            ];
            $packages[] = [
                'title' => 'ستاندرد',
                'plan' => 'standard',
                'active' => (bool) $pkg->standard_active,
                'expires_at' => $pkg->standard_expire_date,
                'days' => (int) ($pkg->standard_days ?? 0),
                'total' => (int) ($pkg->standard_ads ?? 0),
                'used' => (int) ($pkg->standard_ads_used ?? 0),
                'remaining' => (int) $pkg->standard_ads_remaining,
            ];
        }

        return response()->json([
            'subscriptions' => $subscriptions,
            'packages' => $packages,
        ]);
    }


    public function SetRankOne(Request $request)
    {
        $validated = $request->validate([
            'category' => ['required', 'string'], // slug
            'ad_id' => ['required', 'integer'],
        ]);

        $sec = Section::fromSlug($validated['category']);
        $categoryId = $sec->id();

        $ad = Listing::where('id', $validated['ad_id'])
            ->where('category_id', $categoryId)
            ->first();

        if (!$ad) {
            return response()->json([
                'status' => false,
                'message' => 'الإعلان غير موجود في هذا القسم.',
            ], 404);
        }

        $user = $request->user();
        if ($ad->user_id !== ($user->id ?? null)) {
            return response()->json([
                'status' => false,
                'message' => 'لا يمكنك تعديل ترتيب إعلان لا تملكه.',
            ], 403);
        }

        $cooldownHours = 24;
        $cacheKey = "rank1:{$user->id}:cat{$categoryId}:ad{$ad->id}";
        if (Cache::has($cacheKey)) {
            $expiresAtTs = Cache::get($cacheKey);
            $remaining = max(0, $expiresAtTs - now()->timestamp);
            $hrs = (int) ceil($remaining / 3600);

            return response()->json([
                'status' => false,
                'message' => "يمكنك رفع الإعلان مرة كل 24 ساعة. المُتبقي تقريبًا: {$hrs} ساعة.",
            ], 429);
        }


        $ok = $this->makeRankOne($categoryId, $ad->id);
        if (!$ok) {
            return response()->json([
                'status' => false,
                'message' => 'حدث خطأ أثناء تحديث الترتيب.',
            ], 500);
        }

        $expires = now()->addHours($cooldownHours);
        Cache::put($cacheKey, $expires->timestamp, $expires);

        return response()->json([
            'status' => true,
            'message' => "تم رفع الإعلان #{$ad->id} إلى الترتيب الأول في قسم {$sec->name}.",
        ]);
    }

    public function updateNotificationSettings(Request $request)
    {
        $user = $request->user();
        $data = $request->validate([
            'receive_external' => ['required', 'boolean'],
        ]);
        $user->receive_external_notif = (bool) $data['receive_external'];
        $user->save();
        return response()->json([
            'receive_external' => (bool) $user->receive_external_notif,
        ]);
    }

    public function logout(Request $request)
    {
        $user = $request->user();

        $user->currentAccessToken()?->delete();

        return response()->json([
            'message' => 'Successfully logged out from API'
        ], 200);
    }

    public function deleteMyAccount(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'message' => 'User not authenticated'
            ], 401);
        }

        $user->tokens()->delete();
        $user->delete();

        return response()->json([
            'message' => 'Account deleted successfully'
        ], 200);
    }

    //Admin control
    public function blockedUser(Request $request, User $user)
    {
        if ($user->status === 'blocked') {
            $user->update([
                'status' => 'active'
            ]);
            return response()->json([
                'message' => 'User unblocked successfully.'
            ], 200);
        } else {
            $user->update([
                'status' => 'blocked'
            ]);
            $user->tokens()->delete();

            return response()->json([
                'message' => 'User blocked successfully.'
            ], 200);
        }
    }

    // Admin: Show user details
    // public function showUser(User $user)
    // {
    //     $user->loadCount('listings');

    //     return response()->json([
    //         'id' => $user->id,
    //         'name' => $user->name,
    //         'phone' => $user->phone,
    //         'user_code' => $user->referral_code ?: (string) $user->id,
    //         'status' => $user->status ?? 'active',
    //         'registered_at' => optional($user->created_at)->toDateString(),
    //         'listings_count' => $user->listings_count ?? 0,
    //         'role' => $user->role ?? 'user',
    //     ]);
    // }

    // Admin: Show user with listings combined
    public function showUserWithListings(User $user, Request $request)
    {
        $user->loadCount('listings');
        $viewer = $request->user();
        $canViewClickMetrics = $viewer
            && (($viewer->role ?? null) === 'admin' || (int) $viewer->id === (int) $user->id);
        $clickTotals = $canViewClickMetrics ? $this->getUserContactClickTotals($user) : null;

        $singleSlug = $request->query('category_slug') ?? $request->query('slug');
        $multiSlugs = $request->query('category_slugs') ?? $request->query('slugs'); // "a,b,c"
        $statusFilter = $request->query('status'); // Valid / Pending / Rejected / Expired

        $slugs = [];
        if ($singleSlug) {
            $slugs[] = trim($singleSlug);
        }
        if ($multiSlugs) {
            $extra = array_map('trim', explode(',', $multiSlugs));
            $slugs = array_values(array_filter(array_merge($slugs, $extra)));
        }

        $query = Listing::query()
            ->leftJoin('categories', 'listings.category_id', '=', 'categories.id')
            ->with([
                'attributes',
                'governorate',
                'city',
                'make',
                'model',
                // ✅ عشان نقدر نجيب أسماء القسم الرئيسي والفرعي
                'mainSection',
                'subSection',
            ])
            ->where('listings.user_id', $user->id)
            ->when($statusFilter, fn($q) => $q->where('listings.status', $statusFilter))
            ->when(!empty($slugs), fn($q) => $q->whereIn('categories.slug', $slugs))
            ->select([
                'listings.id',
                'listings.category_id',
                'listings.title',  // ✅ إضافة title
                'listings.main_image',
                'listings.make_id',
                'listings.model_id',
                'listings.price',
                'listings.rank',
                'listings.views',
                'listings.whatsapp_clicks',
                'listings.call_clicks',
                'listings.lat',
                'listings.lng',
                'listings.contact_phone',
                'listings.whatsapp_phone',
                'listings.plan_type',
                'listings.created_at',
                'listings.governorate_id',
                'listings.city_id',
                // ✅ مهم لو حابة ترجعي الـ id كمان
                'listings.main_section_id',
                'listings.sub_section_id',
                'categories.slug as category_slug',
            ])
            ->orderByDesc('listings.created_at');

        $rows = $query->get();

        $items = $rows->map(function ($row) use ($canViewClickMetrics) {
            $attrs = [];
            if ($row->relationLoaded('attributes')) {
                foreach ($row->attributes as $attr) {
                    $attrs[$attr->key] = $this->castEavValueRow($attr);
                }
            }

            // أسماء المحافظة/المدينة
            $govName = ($row->relationLoaded('governorate') && $row->governorate) ? $row->governorate->name : null;
            $cityName = ($row->relationLoaded('city') && $row->city) ? $row->city->name : null;

            // بيانات القسم (slug + name)
            $catSlug = $row->category_slug;
            $catName = null;
            $supportsMakeModel = false;
            $supportsSections = false;
            $mainSectionName = null;
            $subSectionName = null;

            if ($row->category_id) {
                $sec = Section::fromId($row->category_id);

                if ($sec) {
                    $catSlug = $sec->slug ?? $catSlug;
                    $catName = $sec->name ?? null;
                    $supportsMakeModel = $sec->supportsMakeModel();
                    $supportsSections = $sec->supportsSections(); // ✅ الدالة اللي اتفقنا عليها
                }
            }
            
            // Get category model for unified image fields
            $cat = $row->category_id ? \App\Models\Category::find($row->category_id) : null;

            // ✅ لو القسم ده بيدعم رئيسي/فرعي، نرجّعهم بالاسم
            if ($supportsSections) {
                $mainSectionName = ($row->relationLoaded('mainSection') && $row->mainSection)
                    ? $row->mainSection->name
                    : null;

                $subSectionName = ($row->relationLoaded('subSection') && $row->subSection)
                    ? $row->subSection->name
                    : null;
            }

            $data = [
                'attributes' => $attrs,
                'governorate' => $govName,
                'city' => $cityName,
                'title' => $row->title,  // ✅ إضافة title
                'price' => $row->price,
                'contact_phone' => $row->contact_phone,
                'whatsapp_phone' => $row->whatsapp_phone,
                'main_image_url' => $row->main_image ? asset('storage/' . $row->main_image) : null,
                'created_at' => $row->created_at,
                'plan_type' => $row->plan_type,
                'id' => $row->id,
                'lat' => $row->lat,
                'lng' => $row->lng,
                'rank' => $row->rank,
                'views' => $row->views,

                // الكاتيجري
                'category' => $catSlug,
                'category_name' => $catName,
                
                // Unified category image fields
                'is_global_image_active' => $cat ? ($cat->is_global_image_active ?? false) : false,
                'global_image_url' => $cat ? $cat->global_image_url : null,
                'global_image_full_url' => $cat ? $cat->global_image_full_url : null,
            ];

            if ($canViewClickMetrics) {
                $data['whatsapp_clicks'] = (int) ($row->whatsapp_clicks ?? 0);
                $data['call_clicks'] = (int) ($row->call_clicks ?? 0);
            }

            // ✅ لو الكاتيجوري ده بيدعم make/model
            if ($supportsMakeModel) {
                $data['make'] = ($row->relationLoaded('make') && $row->make) ? $row->make->name : null;
                $data['model'] = ($row->relationLoaded('model') && $row->model) ? $row->model->name : null;
            }

            // ✅ لو القسم بيدعم رئيسي/فرعي، نضيفهم
            if ($supportsSections) {
                $data['main_section_id'] = $row->main_section_id;
                $data['main_section'] = $mainSectionName;
                $data['sub_section_id'] = $row->sub_section_id;
                $data['sub_section'] = $subSectionName;
            }

            return $data;
        })->values();

        $userPayload = [
            'id' => $user->id,
            'name' => $user->name,
            'phone' => $user->phone,
            'status' => $user->status ?? 'active',
            'role' => $user->role ?? 'user',
            'listings_count' => $user->listings_count ?? 0,
        ];

        if ($canViewClickMetrics && $clickTotals) {
            $userPayload['whatsapp_clicks'] = $clickTotals['whatsapp_clicks'];
            $userPayload['call_clicks'] = $clickTotals['call_clicks'];
        }

        return response()->json([
            'user' => $userPayload,
            'listings' => $items,
            'meta' => ['total' => $items->count()],
        ]);
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
        if (is_null($json))
            return null;
        if (is_array($json))
            return $json;

        $x = json_decode($json, true);
        return json_last_error() === JSON_ERROR_NONE ? $x : $json;
    }


    // Admin: Create user
    public function storeUser(Request $request)
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:100'],
            'phone' => ['required', 'string', 'max:15', 'unique:users,phone'],
            'role' => ['nullable', Rule::in(['user', 'advertiser', 'admin', 'reviewer'])],
            'status' => ['nullable', Rule::in(['active', 'blocked'])],
            'referral_code' => ['nullable', 'string', 'max:20'],
            'password' => ['nullable', 'string', 'min:4', 'max:100'],
        ]);

        $user = new User();
        $user->name = $data['name'] ?? ($data['phone'] ?? 'User');
        $user->phone = $data['phone'];
        $user->role = $data['role'] ?? 'user';
        $user->status = $data['status'] ?? 'active';
        $user->referral_code = $data['referral_code'] ?? null;
        $user->password = Hash::make($data['password'] ?? '123456');
        $user->save();

        $user->loadCount('listings');

        return response()->json([
            'message' => 'User created successfully',
            'user' => $this->formatUserSummary($user),
        ], 201);
    }

    // Admin: Update user data
    public function updateUser(Request $request, User $user)
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:15', Rule::unique('users', 'phone')->ignore($user->id)],
            'role' => ['nullable', Rule::in(['user', 'advertiser', 'admin', 'reviewer'])],
            'status' => ['nullable', Rule::in(['active', 'blocked'])],
            'referral_code' => ['nullable', 'string', 'max:20'],
        ]);

        foreach (['name', 'phone', 'role', 'status', 'referral_code'] as $field) {
            if (array_key_exists($field, $data)) {
                $user->{$field} = $data[$field];
            }
        }
        $user->save();

        $user->loadCount('listings');
        return response()->json([
            'message' => 'User updated successfully',
            'user' => $this->formatUserSummary($user),
        ]);
    }

    // Admin: Delete user
    public function deleteUser(User $user)
    {
        // revoke tokens then delete
        $user->tokens()->delete();
        $user->delete();

        return response()->json(['message' => 'User deleted successfully']);
    }

    // Helper: format user output consistently
    private function formatUserSummary(User $user): array
    {
        $totals = $this->getUserContactClickTotals($user);

        return [
            'id' => $user->id,
            'name' => $user->name,
            'phone' => $user->phone,
            'user_code' => (string) $user->id,
            'delegate_code' => $user->referral_code,
            'status' => $user->status ?? 'active',
            'registered_at' => optional($user->created_at)->toDateString(),
            'listings_count' => $user->listings_count ?? $user->listings()->count(),
            'whatsapp_clicks' => $totals['whatsapp_clicks'],
            'call_clicks' => $totals['call_clicks'],
            'role' => $user->role ?? 'user',
        ];
    }

    private function getUserContactClickTotals(User $user): array
    {
        $totals = Listing::query()
            ->where('user_id', $user->id)
            ->selectRaw('COALESCE(SUM(whatsapp_clicks), 0) as whatsapp_clicks')
            ->selectRaw('COALESCE(SUM(call_clicks), 0) as call_clicks')
            ->first();

        return [
            'whatsapp_clicks' => (int) ($totals->whatsapp_clicks ?? 0),
            'call_clicks' => (int) ($totals->call_clicks ?? 0),
        ];
    }


    //create agent code (make user a representative)

    public function storeAgent(Request $request)
    {
        $user = $request->user();

        // Create or retrieve user_clients record
        $userClient = \App\Models\UserClient::firstOrCreate(
            ['user_id' => $user->id],
            ['clients' => []]
        );

        // Check if user already is a representative
        if ($user->role === 'representative') {
            return response()->json([
                'message' => 'You are already a representative',
                'user_code' => (string) $user->id,
                'role' => $user->role,
                // Backward compatibility - old structure
                // IMPORTANT: id = user_id (not user_clients.id) for app compatibility
                'data' => [
                    'id' => (int) $user->id,  // ← Same as user_id for app compatibility
                    'user_id' => (int) $user->id,
                    'clients' => $userClient->clients ?? [],
                    'created_at' => $userClient->created_at,
                    'updated_at' => $userClient->updated_at,
                ]
            ]);
        }

        // Update user role to representative
        $user->role = 'representative';
        $user->save();

        return response()->json([
            'message' => 'You are now a representative. Your delegate code is: ' . $user->id,
            'user_code' => (string) $user->id,
            'role' => $user->role,
            // Backward compatibility - old structure
            // IMPORTANT: id = user_id (not user_clients.id) for app compatibility
            'data' => [
                'id' => (int) $user->id,  // ← Same as user_id for app compatibility
                'user_id' => (int) $user->id,
                'clients' => $userClient->clients ?? [],
                'created_at' => $userClient->created_at,
                'updated_at' => $userClient->updated_at,
            ]
        ]);
    }

    //get clients for representative
    public function allClients(Request $request)
    {
        $user = $request->user();

        // Check if user is a representative
        if ($user->role !== 'representative') {
            return response()->json([
                'message' => 'Only representatives can view their clients',
                'data' => []
            ], 403);
        }

        $userClient = UserClient::where('user_id', $user->id)->first();

        if (!$userClient || empty($userClient->clients)) {
            return response()->json([
                'message' => 'No clients found',
                'user_code' => (string) $user->id,
                'clients_count' => 0,
                'data' => []
            ]);
        }

        // Get all client users
        $clients = User::whereIn('id', $userClient->clients)
            ->withCount('listings')
            ->get()
            ->map(function ($client) {
                return [
                    'id' => $client->id,
                    'name' => $client->name,
                    'phone' => $client->phone,
                    'user_code' => (string) $client->id,
                    'role' => $client->role,
                    'status' => $client->status ?? 'active',
                    'registered_at' => optional($client->created_at)->toDateString(),
                    'listings_count' => $client->listings_count ?? 0,
                ];
            });

        return response()->json([
            'message' => 'Clients retrieved successfully',
            'user_code' => (string) $user->id,
            'clients_count' => $clients->count(),
            'data' => $clients
        ]);
    }


    //create admin otp
    public function createOtp(User $user)
    {
        // $user = Request()->user();
        // if ($user->role == 'advertiser' || $user->role == 'user' || $user->role == 'representative'){

        // }
        $otp = rand(100000, 999999);
        $user->otp = $otp;
        $user->save();
        // $notifications->dispatch(
        //     (int) $user->id,
        //     'تم إنشاء رمز تحقق',
        //     'تم إصدار رمز تحقق لحسابك.',
        //     'system',
        //     ['reason' => 'otp_created']
        // );
        return response()->json(['message' => 'Otp created successfully', 'otp' => $user->otp]);
    }

    //user verify otp
    public function verifyOtp(Request $request)
    {
        $request->validate([
            'otp' => 'required|string',
        ]);

        $user = User::where('id', $request->user()->id)->first();
        if ($user->otp != $request->otp) {
            return response()->json(['message' => 'Invalid otp'], 401);
        }
        $user->otp_verified = true;
        $user->save();
        return response()->json(['message' => 'Otp verified successfully']);
    }



    //payment
    public function payment(Request $request, $id)
    {
        $userId = $request->user()->id;

        $listing = Listing::where([
            'id' => $id,
            'user_id' => $userId,
        ])->first();

        if (!$listing) {
            return response()->json([
                'success' => false,
                'message' => 'Ad not found',
            ], 404);
        }

        if ($listing->isPayment) {
            return response()->json([
                'success' => true,
                'message' => 'Ad already paid',
                'listing_id' => $listing->id,
            ]);
        }

        $request->validate([
            'payment_method' => ['required', 'string', Rule::in(['instapay', 'wallet', 'visa'])],
            'payment_reference' => ['nullable', 'string'],
        ]);

        $prices = CategoryPlanPrice::where('category_id', $listing->category_id)->first();
        $plan = strtolower($listing->plan_type ?? 'standard');
        $amount = 0.0;
        $days = 0;
        if ($plan === 'featured') {
            $amount = (float) ($prices->featured_ad_price ?? 0);
            $days = (int) ($prices->featured_days ?? 0);
        } elseif ($plan === 'standard') {
            $amount = (float) ($prices->standard_ad_price ?? 0);
            $days = (int) ($prices->standard_days ?? 0);
        }

        $listing->isPayment = true;
        $listing->publish_via = env('LISTING_PUBLISH_VIA_AD_PAYMENT', 'ad_payment');

        $manualApprove = Cache::remember('settings:manual_approval', now()->addHours(6), function () {
            $val = SystemSetting::where('key', 'manual_approval')->value('value');
            return (int) $val === 1;
        });

        if (!$manualApprove) {
            $listing->status = 'Valid';
            $listing->admin_approved = true;
            $listing->published_at = now();
            $listing->expire_at = $days > 0 ? now()->copy()->addDays($days) : now()->addDays(365);
        }

        $listing->save();

        $paidAt = now();
        ListingPayment::updateOrCreate(
            ['listing_id' => $listing->id],
            [
                'user_id' => $userId,
                'category_id' => $listing->category_id,
                'plan_type' => $plan,
                'amount' => $amount,
                'currency' => $listing->currency,
                'paid_at' => $paidAt,
                'payment_reference' => $request->input('payment_reference') ?? '<transaction-id-from-gateway>',
                'status' => 'paid',
                'payment_method' => $request->input('payment_method'),
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Payment done successfully',
            'listing_id' => $listing->id,
            'amount' => $amount,
            'price' => $amount,
            'paid_at' => $paidAt->toIso8601String(),
        ]);
    }
    
    /**
     * حفظ/تحديث FCM Token للمستخدم
     * POST /api/fcm-token
     */
    public function updateFcmToken(Request $request)
    {
        $data = $request->validate([
            'fcm_token' => ['required', 'string', 'max:255'],
        ]);

        $user = $request->user();
        $user->fcm_token = $data['fcm_token'];
        $user->save();

        return response()->json([
            'message' => 'FCM token updated successfully',
        ]);
    }

    /**
     * حذف FCM Token (عند تسجيل الخروج)
     * DELETE /api/fcm-token
     */
    public function deleteFcmToken(Request $request)
    {
        $user = $request->user();
        $user->fcm_token = null;
        $user->save();

        return response()->json([
            'message' => 'FCM token deleted successfully',
        ]);
    }

    /**
     * Admin: Get all clients for a specific representative.
     * GET /api/admin/delegates/{user}/clients
     */
    public function delegateClients(User $user)
    {
        // Find the client relationship record for this user (representative)
        $userClient = UserClient::where('user_id', $user->id)->first();

        if (!$userClient || empty($userClient->clients)) {
            return response()->json([
                'success' => true,
                'message' => 'No clients found for this representative',
                'data' => []
            ]);
        }

        // Fetch users based on the list of client IDs
        $clients = User::whereIn('id', $userClient->clients)
            ->withCount('listings')
            ->get()
            ->map(function ($u) {
                return [
                    'id' => $u->id,
                    'name' => $u->name,
                    'phone' => $u->phone,
                    'address' => $u->address,
                    'lat' => $u->lat,
                    'lng' => $u->lng,
                    'status' => $u->status ?? 'active',
                    'role' => $u->role ?? 'user',
                    'user_code' => (string) $u->id,
                    'delegate_code' => $u->referral_code,
                    'registered_at' => optional($u->created_at)->toDateString(),
                    'listings_count' => $u->listings_count ?? 0,
                    'phone_verified' => (bool) $u->otp_verified,
                ];
            });

        return response()->json([
            'success' => true,
            'message' => 'Clients retrieved successfully',
            'data' => $clients
        ]);
    }
}
