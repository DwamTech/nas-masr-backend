<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ListingResource;
use App\Models\Favorite;
use App\Models\Listing;
use App\Support\Section;
use Illuminate\Http\Request;

class FavoriteController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $rows = Favorite::query()
            ->where('user_id', $user->id)
            ->with(['ad.governorate', 'ad.city'])
            ->get();

        $payload = $rows->map(function ($f) {
            $ad = $f->ad;
            if (!$ad) return null;

            $slug = $ad->category_id ? Section::fromId($ad->category_id)->slug : null;
            $sec = $ad->category_id ? Section::fromId($ad->category_id) : null;

            return [
                'plan_type'  => $ad->plan_type,
                'price'      => $ad->price,
                'title'      => $ad->title,
                'description'=> $ad->description,
                'gov'        => optional($ad->governorate)->name,
                'cite'       => optional($ad->city)->name,
                'puplished'  => $ad->published_at,
                'main_image' => $ad->main_image ? asset('storage/' . $ad->main_image) : null,
                'view'       => $ad->views,
                'id'         => $ad->id,
                'rank'       => $ad->rank,
                'categry'    => $slug,
                'categry_name'=> $slug ? Section::fromId($ad->category_id)->name : null,
                
                // Unified category image fields
                'is_global_image_active' => $sec ? ($sec->is_global_image_active ?? false) : false,
                'global_image_url' => $sec ? $sec->global_image_url : null,
                'global_image_full_url' => $sec ? $sec->global_image_full_url : null,
            ];
        })->filter();

        return response()->json([
            'count' => $payload->count(),
            'data'  => $payload->values(),
        ]);
    }

    public function toggle(Request $request)
    {
        $data = $request->validate([
            'ad_id' => ['required', 'integer', 'exists:listings,id'],
        ]);

        $user = $request->user();

        $existing = Favorite::query()
            ->where('user_id', $user->id)
            ->where('ad_id', $data['ad_id'])
            ->first();

        if ($existing) {
            $existing->delete();
            return response()->json([
                'message' => 'تم إزالة الإعلان من المفضلة',
                'favorited' => false,
            ]);
        }

        $ad = Listing::find($data['ad_id']);
        $slug = Section::fromId($ad->category_id)->slug;

        Favorite::create([
            'user_id' => $user->id,
            'ad_id' => $ad->id,
            'category_slug' => $slug,
        ]);

        return response()->json([
            'message' => 'تم إضافة الإعلان إلى المفضلة',
            'favorited' => true,
        ], 201);
    }
}

