<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\CategoryBanner;
use Illuminate\Support\Facades\File;

class CategoryBannerSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $allowedSlugs = [
            'real_estate',
            'cars',
            'cars_rent', // Note: cars_rent folder might not exist, will fallback
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
            'payment_single_ad_methods',
            'payment_packages_subscribe',
            'unified'
        ];

        // Specific files found in previous step
        // teachers: teachers\3335fedb1473bdce7e4010afa7797f40681183a6.png
        // doctors: doctors\3335fedb1473bdce7e4010afa7797f40681183a6.png
        // spare-parts: spare-parts\6576daa19cc0b4156e90af5f4b675a378f7a1ec5.png
        // jobs: jobs\184511e94a0be2f1eaa8faf76ad2ee376b7a0778.png
        // furniture: furniture\01700436e6f9335a127463ae98854f51d74f3da5.png
        // cars: cars\f61398e71267cc1819fb1673c88cf6088664a6cf.png
        // real_estate: real_estate\796c4c36d93281ccfb0cac71ed31e5d1b182ae79.png
        // 53228567cbfa1e8ef884e31013cba35dffde42d3.jpg (root banner folder)

        // Default furniture path
        $defaultFurniturePath = 'storage/uploads/banner/furniture/01700436e6f9335a127463ae98854f51d74f3da5.png';

        foreach ($allowedSlugs as $slug) {
            $bannerPath = null;
            
            // Check if specific folder exists and has files
            $dirPath = public_path("storage/uploads/banner/{$slug}");
            if (File::isDirectory($dirPath)) {
                $files = File::files($dirPath);
                if (count($files) > 0) {
                    $filename = $files[0]->getFilename();
                    $bannerPath = "storage/uploads/banner/{$slug}/{$filename}";
                }
            }

            // If no specific image found, use default furniture image
            if (!$bannerPath) {
                $bannerPath = $defaultFurniturePath;
            }

            CategoryBanner::updateOrCreate(
                ['slug' => $slug],
                [
                    'banner_path' => $bannerPath,
                    'is_active' => true
                ]
            );
        }
    }
}
