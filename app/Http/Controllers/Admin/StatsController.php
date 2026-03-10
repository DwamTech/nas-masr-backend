<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Listing;
use App\Models\CategoryPlanPrice;
use App\Models\UserPlanSubscription;
use App\Models\UserPackages;
use App\Models\SystemSetting;
use App\Models\Category;
use App\Models\CategoryField;
use App\Http\Resources\ListingResource;
use App\Services\NotificationService;
use App\Support\Section;
use App\Models\Notification;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use App\Models\User;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class StatsController extends Controller
{
    public function index(): JsonResponse
    {
        $now = Carbon::now();

        $currentStart = $now->copy()->startOfMonth();
        $currentEnd   = $now->copy()->endOfMonth();

        $prevStart = $now->copy()->subMonthNoOverflow()->startOfMonth();
        $prevEnd   = $now->copy()->subMonthNoOverflow()->endOfMonth();

        $totalAll         = Listing::query()->count();
        $totalAllCurrent  = Listing::query()->whereBetween('created_at', [$currentStart, $currentEnd])->count();
        $totalAllPrev     = Listing::query()->whereBetween('created_at', [$prevStart, $prevEnd])->count();

        $totalPending        = Listing::query()->where('status', 'Pending')->count();
        $totalPendingCurrent = Listing::query()
            ->where('status', 'Pending')
            ->whereBetween('created_at', [$currentStart, $currentEnd])
            ->count();
        $totalPendingPrev    = Listing::query()
            ->where('status', 'Pending')
            ->whereBetween('created_at', [$prevStart, $prevEnd])
            ->count();

        $totalRejected        = Listing::query()->where('status', 'Rejected')->count();
        $totalRejectedCurrent = Listing::query()
            ->where('status', 'Rejected')
            ->whereBetween('created_at', [$currentStart, $currentEnd])
            ->count();
        $totalRejectedPrev    = Listing::query()
            ->where('status', 'Rejected')
            ->whereBetween('created_at', [$prevStart, $prevEnd])
            ->count();

        $totalActive        = Listing::query()->active()->count();
        $totalActiveCurrent = Listing::query()->active()
            ->whereBetween('created_at', [$currentStart, $currentEnd])
            ->count();
        $totalActivePrev    = Listing::query()->active()
            ->whereBetween('created_at', [$prevStart, $prevEnd])
            ->count();

        $makeStat = function (int $total, int $current, int $prev): array {
            if ($prev === 0) {
                $percent = $current > 0 ? 100.0 : 0.0;
            } else {
                $percent = round((($current - $prev) / $prev) * 100, 2);
            }

            return [
                'count'     => $total,
                'percent'   => $percent,             // 8.5 مثلاً
                'direction' => $percent >= 0 ? 'up' : 'down', // علشان الفرونت يحط علامة + أو سهم
            ];
        };

        return response()->json([
            'cards' => [
                'rejected' => $makeStat($totalRejected, $totalRejectedCurrent, $totalRejectedPrev),
                'pending'  => $makeStat($totalPending,  $totalPendingCurrent,  $totalPendingPrev),
                'active'   => $makeStat($totalActive,   $totalActiveCurrent,   $totalActivePrev),
                'total'    => $makeStat($totalAll,      $totalAllCurrent,      $totalAllPrev),
            ],
            'periods' => [
                'current_month' => [
                    'start' => $currentStart->toDateString(),
                    'end'   => $currentEnd->toDateString(),
                ],
                'previous_month' => [
                    'start' => $prevStart->toDateString(),
                    'end'   => $prevEnd->toDateString(),
                ],
            ],
        ]);
    }
    public function recentActivities(Request $request): JsonResponse
    {
        $limit = (int) $request->query('limit', 20);
        Carbon::setLocale('ar');

        // Listings recent updates
        $listings = Listing::query()
            ->orderBy('updated_at', 'desc')
            ->take($limit)
            ->get()
            ->map(function (Listing $l) {
                $type = 'listing_updated';
                $message = 'تم تحديث إعلان';

                if ($l->status === 'Rejected') {
                    $type = 'listing_rejected';
                    $message = 'تم رفض إعلان';
                } elseif ($l->status === 'Pending') {
                    $type = 'listing_pending';
                    $message = 'تم تعليق إعلان';
                } elseif ($l->admin_approved) {
                    $type = 'listing_approved';
                    $message = 'تم تفعيل إعلان';
                } else {
                    $type = 'listing_disabled';
                    $message = 'تم تعطيل إعلان';
                }

                $ts = Carbon::parse($l->updated_at);
                return [
                    'type' => $type,
                    'message' => $message,
                    'entity' => 'listing',
                    'id' => $l->id,
                    'status' => $l->status,
                    'admin_approved' => (bool) $l->admin_approved,
                    'timestamp' => $ts->toIso8601String(),
                    'ago' => $ts->diffForHumans(),
                ];
            });

        // System settings updates
        $settings = SystemSetting::query()
            ->orderBy('updated_at', 'desc')
            ->take($limit)
            ->get()
            ->map(function (SystemSetting $s) {
                $ts = Carbon::parse($s->updated_at);
                return [
                    'type' => 'settings_updated',
                    'message' => 'تم تحديث الإعدادات',
                    'entity' => 'system_settings',
                    'id' => $s->id,
                    'timestamp' => $ts->toIso8601String(),
                    'ago' => $ts->diffForHumans(),
                ];
            });

        // Optional: category and category-fields updates in admin panel
        $categories = Category::query()
            ->orderBy('updated_at', 'desc')
            ->take($limit)
            ->get()
            ->map(function (Category $c) {
                $ts = Carbon::parse($c->updated_at);
                return [
                    'type' => 'category_updated',
                    'message' => 'تم تحديث قسم',
                    'entity' => 'category',
                    'id' => $c->id,
                    'timestamp' => $ts->toIso8601String(),
                    'ago' => $ts->diffForHumans(),
                ];
            });

        $categoryFields = CategoryField::query()
            ->orderBy('updated_at', 'desc')
            ->take($limit)
            ->get()
            ->map(function (CategoryField $f) {
                $ts = Carbon::parse($f->updated_at);
                return [
                    'type' => 'category_field_updated',
                    'message' => 'تم تحديث حقل قسم',
                    'entity' => 'category_field',
                    'id' => $f->id,
                    'timestamp' => $ts->toIso8601String(),
                    'ago' => $ts->diffForHumans(),
                ];
            });

        $activities = collect()
            ->merge($listings)
            ->merge($settings)
            ->merge($categories)
            ->merge($categoryFields)
            ->sortByDesc('timestamp')
            ->take($limit)
            ->values();

        return response()->json([
            'count' => $activities->count(),
            'activities' => $activities,
        ]);
    }


    public function usersSummary(Request $request): JsonResponse
    {
        $perPage = (int) $request->query('per_page', 20);
        $role = $request->query('role'); // admin, user, reviewer, advertiser
        $status = $request->query('status'); // active, blocked
        $q = trim((string) $request->query('q', ''));

        $users = User::query()
            ->when($role, fn($qr) => $qr->where('role', $role))
            ->when($status, fn($qr) => $qr->where('status', $status))
            ->when($q !== '', function ($qr) use ($q) {
                $qr->where(function ($qq) use ($q) {
                    $qq->where('name', 'like', "%$q%")
                        ->orWhere('phone', 'like', "%$q%")
                        ->orWhere('referral_code', 'like', "%$q%");
                });
            })
            ->withCount('listings')
            ->withSum('listings as whatsapp_clicks', 'whatsapp_clicks')
            ->withSum('listings as call_clicks', 'call_clicks')
            ->orderByDesc('created_at')
            ->paginate($perPage);

        $data = collect($users->items())->map(function (User $u) {
            return [
                'id' => $u->id,
                'name' => $u->name,
                'phone' => $u->phone,
                'address' => $u->address,
                'user_code' => (string) $u->id,  // Always use ID as user_code
                'delegate_code' => $u->referral_code,  // Delegate who brought this user
                'status' => $u->status ?? 'active',
                'registered_at' => optional($u->created_at)->toDateString(),
                'listings_count' => $u->listings_count ?? 0,
                'whatsapp_clicks' => (int) ($u->whatsapp_clicks ?? 0),
                'call_clicks' => (int) ($u->call_clicks ?? 0),
                'role' => $u->role ?? 'user',
                'phone_verified' => (bool) $u->otp_verified,
            ];
        })->values();

        return response()->json([
            'meta' => [
                'page' => $users->currentPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
                'last_page' => $users->lastPage(),
            ],
            'users' => $data,
        ]);
    }

    public function pendingListings(Request $request): JsonResponse
    {
        $perPage = (int) $request->query('per_page', 50);
        $listings = Listing::query()
            ->where('status', 'Pending')
            ->whereNotNull('publish_via')
            ->with(['attributes', 'governorate', 'city', 'make', 'model', 'mainSection', 'subSection', 'user'])
            ->orderByDesc('created_at')
            ->paginate($perPage);

        $items = ListingResource::collection(collect($listings->items()));

        return response()->json([
            'meta' => [
                'page' => $listings->currentPage(),
                'per_page' => $listings->perPage(),
                'total' => $listings->total(),
                'last_page' => $listings->lastPage(),
            ],
            'listings' => $items,
        ]);
    }

    public function adsNOTPayment(Request $request)
    {
        $perPage = (int) $request->query('per_page', 50);
        $listings = Listing::query()
            ->where('status', 'Pending')
            
            ->where('publish_via', null)
            ->where('isPayment', false)
            ->where('plan_type', '!=', 'free')
            ->with(['attributes', 'governorate', 'city', 'make', 'model', 'mainSection', 'subSection', 'user'])
            ->orderByDesc('created_at')
            ->paginate($perPage);

        $items = ListingResource::collection(collect($listings->items()));

        return response()->json([
            'meta' => [
                'page' => $listings->currentPage(),
                'per_page' => $listings->perPage(),
                'total' => $listings->total(),
                'last_page' => $listings->lastPage(),
            ],
            'listings' => $items,
        ]);
    }

    public function publishedListings(Request $request): JsonResponse
    {
        $perPage = (int) $request->query('per_page', 20);
        $listings = Listing::query()
            ->where('status', 'Valid')
            ->with(['user'])
            ->orderByDesc('published_at')
            ->paginate($perPage);

        $items = collect($listings->items())->map(function (Listing $l) {
            $sec = Section::fromId((int) $l->category_id);
            return [
                'status' => 'منشور',
                'id' => $l->id,
                'category_slug' => $sec?->slug,
                'category_name' => $sec?->name,
                'published_at' => optional($l->published_at)->toDateString(),
                'expire_at' => optional($l->expire_at)->toDateString(),
                'plan_type' => $l->plan_type,
                'price' => (float) ($l->price ?? 0),
                'views' => (int) ($l->views ?? 0),
                'advertiser_id' => (int) $l->user_id,
                'advertiser_phone' => $l->relationLoaded('user') && $l->user ? $l->user->phone : null,
            ];
        })->values();

        return response()->json([
            'meta' => [
                'page' => $listings->currentPage(),
                'per_page' => $listings->perPage(),
                'total' => $listings->total(),
                'last_page' => $listings->lastPage(),
            ],
            'listings' => $items,
        ]);
    }

    public function rejectedListings(Request $request): JsonResponse
    {
        $perPage = (int) $request->query('per_page', 50);
        $listings = Listing::query()
            ->where('status', 'Rejected')
            ->with(['user'])
            ->orderByDesc('updated_at')
            ->paginate($perPage);

        $items = collect($listings->items())->map(function (Listing $l) {
            $sec = Section::fromId((int) $l->category_id);
            return [
                'status' => 'مرفوض',
                'id' => $l->id,
                'category_name' => $sec?->name,
                'category_slug' => $sec?->slug,
                'created_at' => optional($l->created_at)->toDateString(),
                'expire_at' => optional($l->expire_at)->toDateString(),
                'rejected_by' => 'مشرف النظام',
                'rejection_reason' => $l->admin_comment,
                'advertiser_id' => (int) $l->user_id,
                'advertiser_phone' => $l->relationLoaded('user') && $l->user ? $l->user->phone : null,
                'views' => (int) ($l->views ?? 0),
            ];
        })->values();

        return response()->json([
            'meta' => [
                'page' => $listings->currentPage(),
                'per_page' => $listings->perPage(),
                'total' => $listings->total(),
                'last_page' => $listings->lastPage(),
            ],
            'listings' => $items,
        ]);
    }

    public function approveListing(Request $request, Listing $listing): JsonResponse
    {
        // if ($listing->status !== 'Pending') {
        //     return response()->json(['message' => 'listing_not_pending'], 400);
        // }

        $plan = strtolower($listing->plan_type);
        $publishVia = $listing->publish_via ?? env('LISTING_PUBLISH_VIA_ADMIN_APPROVAL', 'admin_approval');
        if ($listing->publish_via == null) {
            $listing->publish_via = $publishVia;
        }

        $expireTs = null;
        if ($publishVia === env('LISTING_PUBLISH_VIA_SUBSCRIPTION', 'subscription')) {
            $sub = UserPlanSubscription::query()
                ->where('user_id', $listing->user_id)
                ->where('category_id', $listing->category_id)
                ->where('plan_type', $plan)
                ->where('payment_status', 'paid')
                ->orderByDesc('id')
                ->first();
            if ($sub) {
                $expireTs = $sub->expires_at;
            }
        } else if (!$expireTs && $publishVia === env('LISTING_PUBLISH_VIA_PACKAGE', 'package')) {
            $pkg = UserPackages::where('user_id', $listing->user_id)->first();
            if ($pkg) {
                if ($plan === 'featured') {
                    $expireTs = $pkg->featured_expire_date; // null means unlimited
                } elseif ($plan === 'standard') {
                    $expireTs = $pkg->standard_expire_date;  // null means unlimited
                }
            }
        } else if (!$expireTs && $publishVia === env('LISTING_PUBLISH_VIA_ADMIN_APPROVAL', 'admin_approval')) {
            $pkg = UserPackages::where('user_id', $listing->user_id)->first();
            if ($pkg) {
                if ($plan === 'featured') {
                    $expireTs = $pkg->featured_expire_date; // null means unlimited
                } elseif ($plan === 'standard') {
                    $expireTs = $pkg->standard_expire_date;  // null means unlimited
                }
            } else {
                $days = Cache::remember('settings:free_ad_days_validity', now()->addHours(6), function () {
                    return (int)(SystemSetting::where('key', 'free_ad_days_validity')->value('value') ?? 365);
                });
                $expireTs = now()->copy()->addDays($days);
            }
        } else {
            $days = Cache::remember('settings:free_ad_days_validity', now()->addHours(6), function () {
                return (int)(SystemSetting::where('key', 'free_ad_days_validity')->value('value') ?? 365);
            });
            $expireTs = now()->copy()->addDays($days);
        }

        $listing->status = 'Valid';
        $listing->admin_approved = true;
        $listing->published_at = now();
        $listing->expire_at = $expireTs;

        // $comment = $request->input('admin_comment');
        // if ($comment !== null) {
        $listing->admin_comment = null;
        // }
        app(NotificationService::class)->dispatch(
            (int) $listing->user_id,
            'تمت الموافقة على إعلانك',
            'تمت الموافقة على إعلانك #' . $listing->id . ' وهو الآن منشور.',
            'الاداره',
            ['listing_id' => (int) $listing->id, 'status' => 'Valid']
        );
        $listing->save();

        return response()->json(new ListingResource($listing->load(['attributes', 'governorate', 'city', 'make', 'model', 'mainSection', 'subSection'])));
        // return Response()->json([
        //     'data'=>[
        //         'expire_at'=>$expireTs,
        //         'publishVia'=>$sub
        //     ]
        // ]);
    }
    //Admin  accept ads not payment
    // public function AcceptAdsNotPayment(Request $request, Listing $listing): JsonResponse
    // {
    //     $plan = strtolower($listing->plan_type ?? 'standard');
    //     // $prices = CategoryPlanPrice::where('category_id', $listing->category_id)->first();
    //     $days = 365;
    //     if ($plan === 'featured') {
    //         $days = (int) ($prices->featured_days ?? 0);
    //     } elseif ($plan === 'standard') {
    //         $days = (int) ($prices->standard_days ?? 0);
    //     } else {
    //         $days = 365;
    //     }

    //     $listing->status = 'Valid';
    //     $listing->admin_approved = true;
    //     $listing->published_at = now();
    //     $listing->expire_at = $days > 0 ? now()->copy()->addDays($days) : null;
    //     $listing->publish_via = env('LISTING_PUBLISH_VIA_FREE', 'free');
    //     $listing->admin_comment = null;
    //     $listing->save();

    //     return response()->json(new ListingResource($listing->load(['attributes', 'governorate', 'city', 'make', 'model', 'mainSection', 'subSection'])));
    // }

    public function rejectListing(Request $request, Listing $listing): JsonResponse
    {
        // if ($listing->status !== 'Pending') {
        //     return response()->json(['message' => 'listing_not_pending'], 400);
        // }

        $data = $request->validate([
            'reason' => ['required', 'string'],
        ]);

        $listing->status = 'Rejected';
        $listing->admin_approved = false;
        $listing->admin_comment = $data['reason'];
        $listing->save();
        app(NotificationService::class)->dispatch(
            (int) $listing->user_id,
            'تم رفض إعلانك',
            'تم رفض إعلانك #' . $listing->id . ' من الإدارة. السبب: ' . $data['reason'],
            'الاداره',
            [
                'listing_id' => (int) $listing->id,
                'reason' => $data['reason'],
                'status' => 'Rejected',
            ]
        );

        return response()->json(new ListingResource($listing->load(['attributes', 'governorate', 'city', 'make', 'model', 'mainSection', 'subSection'])));
    }

    /**
     * Reopen/Re-evaluate the rejected listing.
     */
    public function reopen(Listing $listing, NotificationService $notificationService): JsonResponse
    {
        if ($listing->status !== 'Rejected') {
            return response()->json([
                'message' => 'Listing is not rejected.',
            ], 400);
        }

        // Reset listing status to Pending (or Valid depending on logic, usually Pending for re-review)
        // If automatic approval is ON, we might want to set it to Valid directly.
        // For now, let's stick to Pending to be safe, allowing admin to approve it properly.
        // However, if the admin clicked "Re-evaluate", they might expect it to be active if they think it's valid.
        // Let's check the system setting here again or just default to Pending.

        // Let's check for automatic approval setting just in case
        $manualApprove = Cache::remember('settings:manual_approval', now()->addHours(6), function () {
            $val = SystemSetting::where('key', 'manual_approval')->value('value');
            return (int) $val === 1;
        });

        if ($manualApprove) {
            $listing->update([
                'status' => 'Pending',
                'admin_comment' => null,
                'admin_approved' => false,
            ]);
        } else {
            $listing->update([
                'status' => 'Valid',
                'admin_approved' => true,
                'admin_comment' => null,
            ]);
        }

        // Notify the listing owner
        if ($listing->user_id) {
            $notificationService->dispatch(
                $listing->user_id,
                'إعادة النظر في إعلانك',
                "تم إعادة فتح إعلانك للمراجعة مرة أخرى.",
                'listing_reopened',
                ['listing_id' => $listing->id]
            );
        }

        return response()->json([
            'message' => 'Listing reopened for review.',
        ]);
    }
}
