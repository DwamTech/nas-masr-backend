<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CategoryBanner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class BannerController extends Controller
{
    // The list of allowed slugs
    protected $allowedSlugs = [
        'real_estate',
        'cars',
        'cars_rent',
        'spare-parts',
        'stores',
        'restaurants',
        'groceries',
        'food-products',
        'electronics',
        'home-appliances',
        'home-tools',
        'furniture',
        'doctors',
        'health',
        'teachers',
        'education',
        'jobs',
        'shipping',
        'mens-clothes',
        'watches-jewelry',
        'free-professions',
        'kids-toys',
        'gym',
        'construction',
        'maintenance',
        'car-services',
        'home-services',
        'lighting-decor',
        'animals',
        'farm-products',
        'wholesale',
        'production-lines',
        'light-vehicles',
        'heavy-transport',
        'tools',
        'missing',
        'home_ads',
        'home',
        'payment_single_ad_methods',
        'unified' // fallback
    ];

    public function index()
    {
        $dbBanners = CategoryBanner::all()->keyBy('slug');
        $banners = [];

        foreach ($this->allowedSlugs as $slug) {
            $bannerUrl = null;
            if (isset($dbBanners[$slug]) && $dbBanners[$slug]->banner_path) {
                // If path stored is relative like "storage/...", wrap in asset()
                // If stored as full path, use as is. Assuming relative "storage/uploads/..."
                $bannerUrl = asset($dbBanners[$slug]->banner_path);
            }

            $banners[] = [
                'slug' => $slug,
                'banner_url' => $bannerUrl
            ];
        }

        return response()->json([
            'success' => true,
            'data' => $banners
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'slug' => 'required|string|in:' . implode(',', $this->allowedSlugs),
            'image' => 'required|image|mimes:jpeg,png,jpg,gif,webp|max:40096'
        ]);

        $slug = $request->input('slug');

        // Check if banner already exists for this slug
        $exists = CategoryBanner::where('slug', $slug)->exists();
        if ($exists) {
            return response()->json([
                'success' => false,
                'message' => 'هذا القسم يحتوي على بانر بالفعل. يرجى استخدام التعديل لتغييره.',
                'errors' => ['slug' => ['This category already has a banner.']]
            ], 422);
        }

        $file = $request->file('image');

        return $this->handleBannerUpload($slug, $file);
    }

    public function update(Request $request, $slug)
    {
        if (!in_array($slug, $this->allowedSlugs)) {
            return response()->json([
                'success' => false,
                'message' => 'The selected slug is invalid.',
                'errors' => ['slug' => ['The selected slug is invalid.']]
            ], 422);
        }

        $request->validate([
            'image' => 'required|image|mimes:jpeg,png,jpg,gif,webp|max:40096'
        ]);

        $file = $request->file('image');

        return $this->handleBannerUpload($slug, $file);
    }

    private function handleBannerUpload($slug, $file)
    {
        // Path structure: storage/uploads/banner/{slug}/filename
        $directory = public_path("storage/uploads/banner/{$slug}");

        // Ensure directory exists
        if (!File::isDirectory($directory)) {
            File::makeDirectory($directory, 0755, true);
        }

        // Clean existing files (optional, but good to keep folder clean)
        // Also we might want to delete the old file referenced in DB
        $currentBanner = CategoryBanner::where('slug', $slug)->first();
        if ($currentBanner && $currentBanner->banner_path) {
            $oldPath = public_path($currentBanner->banner_path);
            if (File::exists($oldPath)) {
                File::delete($oldPath);
            }
        }

        // Also clean folder just in case (as per previous logic)
        // But be careful if multiple records point to same folder (unlikely here)
        $files = File::files($directory);
        foreach ($files as $f) {
            File::delete($f);
        }

        // Save new file
        $filename = Str::random(40) . '.' . $file->getClientOriginalExtension();
        $file->move($directory, $filename);

        $relativePath = "storage/uploads/banner/{$slug}/{$filename}";

        // Update or Create DB record
        $banner = CategoryBanner::updateOrCreate(
            ['slug' => $slug],
            [
                'banner_path' => $relativePath,
                'is_active' => true
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Banner updated successfully',
            'data' => [
                'slug' => $banner->slug,
                'banner_url' => asset($banner->banner_path)
            ]
        ]);
    }
}
