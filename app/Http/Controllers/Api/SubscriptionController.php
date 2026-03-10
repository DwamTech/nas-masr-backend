<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CategoryPlanPrice;
use App\Models\UserPlanSubscription;
use App\Models\Listing;
use App\Models\SystemSetting;
use App\Models\UserPackages;
use App\Support\Section;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Cache;


class SubscriptionController extends Controller
{
    public function pricesByCategory(Request $request)
    {
        $slug = $request->query('category_slug') ?? $request->query('slug');
        $sec = Section::fromSlug($slug);
        $row = CategoryPlanPrice::where('category_id', $sec->id())->first();
        $packageSelectionAdsCount = Cache::remember('settings:package_selection_ads_count', now()->addHours(6), function () {
            return (int) (SystemSetting::where('key', 'package_selection_ads_count')->value('value') ?? 0);
        });
        return response()->json([
            'category_id' => $sec->id(),
            'category_slug' => $sec->slug,
            'price_featured' => (float) ($row->price_featured ?? 0),
            'featured_ad_price' => (float) ($row->featured_ad_price ?? 0),
            'featured_days' => (int) ($row->featured_days ?? 0),
            'price_standard' => (float) ($row->price_standard ?? 0),
            'standard_ad_price' => (float) ($row->standard_ad_price ?? 0),
            'standard_days' => (int) ($row->standard_days ?? 0),
            'package_selection_ads_count' => $packageSelectionAdsCount,
        ]);
    }

    public function subscribe(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'category_slug' => ['required', 'string'],

            'plan_type' => [
                'required',
                'string',
                Rule::in(['featured', 'standard']),
            ],

            'payment_method' => [
                'required',
                'string',
                Rule::in(['instapay', 'wallet', 'visa']),
            ],

            // كل الطرق دي أونلاين → لازم رقم أو كود مرجعي للعملية
            // 'payment_reference' => ['required', 'string', 'max:191'],
        ]);
        $sec = Section::fromSlug($data['category_slug']);
        $plan = strtolower($data['plan_type']);

        $existingSub = UserPlanSubscription::query()
            ->where('user_id', $user->id)
            ->where('category_id', $sec->id())
            ->where('plan_type', $plan)
            ->where('payment_status', 'paid')
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>=', now());
            })
            ->first();
        if ($existingSub) {
            return response()->json([
                'message' => 'لديك اشتراك مدفوع فعّال لهذه الخطة بالفعل.',
                'subscription' => $existingSub,
            ], 409);
        }

        $pkg = UserPackages::where('user_id', $user->id)->first();
        if ($pkg) {
            $activeByPlan = $plan === 'featured' ? (bool)$pkg->featured_active : (bool)$pkg->standard_active;
            $remain = $plan === 'featured' ? (int)$pkg->featured_ads_remaining : (int)$pkg->standard_ads_remaining;
            if ($activeByPlan && $remain > 0) {
                return response()->json([
                    'message' => 'لديك باقة فعّالة ورصيد متاح لهذه الخطة، استخدم الباقة بدل الاشتراك.',
                    'package_id' => $pkg->id,
                    'plan' => $plan,
                    'remaining' => $remain,
                    'expire_date' => $plan === 'featured' ? $pkg->featured_expire_date : $pkg->standard_expire_date,
                ], 422);
            }
        }

        $prices = CategoryPlanPrice::where('category_id', $sec->id())->first();
        if (!$prices) {
            return response()->json([
                'message' => 'لم يتم ضبط أسعار هذه الباقة لهذا القسم بعد. برجاء مراجعة الإدارة.',
            ], 422);
        }

        $days = $plan === 'featured'
            ? (int) ($prices->featured_days ?? 0)
            : (int) ($prices->standard_days ?? 0);

        $price = $plan === 'featured'
            ? (float) ($prices->price_featured ?? 0)
            : (float) ($prices->price_standard ?? 0);

        $adPrice = $plan === 'featured'
            ? (float) ($prices->featured_ad_price ?? 0)
            : (float) ($prices->standard_ad_price ?? 0);

        $adsTotal = $plan === 'featured'
            ? (int) ($prices->featured_ads_count ?? 0)
            : (int) ($prices->standard_ads_count ?? 0);

        $start   = now();
        $expires = $days > 0 ? now()->copy()->addDays($days) : null;

        $sub = UserPlanSubscription::updateOrCreate(
            [
                'user_id'     => $user->id,
                'category_id' => $sec->id(),
                'plan_type'   => $plan,
            ],
            [
                'days'              => $days,
                'subscribed_at'     => $start,
                'expires_at'        => $expires,
                'price'             => $price,
                'ad_price'          => $adPrice,
                'ads_total'         => $adsTotal,
                'ads_used'          => 0,
                'payment_status'    => 'paid',
                'payment_reference' => $data['payment_reference']??' "<transaction-id-from-gateway>"',
                'payment_method'    => $data['payment_method'], 
            ]
        );

        $manualApprove = Cache::remember('settings:manual_approval', now()->addHours(6), function () {
            $val = SystemSetting::where('key', 'manual_approval')->value('value');
            return (int) $val === 1;
        });
        $ad = Listing::query()
            ->where('user_id', $user->id)
            ->where('category_id', $sec->id())
            ->where('plan_type', $plan)
            ->where('isPayment',false);

        $ad->update(['publish_via' => env('LISTING_PUBLISH_VIA_SUBSCRIPTION', 'subscription')]);
        if (!$manualApprove) {
            $ad->where('status', 'Pending')
                ->update([
                    'status' => 'Valid',
                    'admin_approved' => true,
                    'published_at' => now(),
                    'expire_at' => $expires,
                ]);
        }

        return response()->json([
            'message'      => 'Subscription created',
            'subscription' => $sub,
        ], 201);
    }

    public function mySubscription(Request $request)
    {
        $user = $request->user();

        $slug = $request->query('category_slug');
        $sec = Section::fromSlug($slug);
        $sub = UserPlanSubscription::where('user_id', $user->id)
            ->where('category_id', $sec->id())
            ->orderByDesc('id')
            ->first();
        $active = false;
        if ($sub) {
            $active = !$sub->expires_at || $sub->expires_at->isFuture();
        }
        return response()->json([
            'active' => $active,
            'subscription' => $sub,
        ]);
    }
}
