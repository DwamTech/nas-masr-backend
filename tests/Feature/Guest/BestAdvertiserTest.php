<?php

namespace Tests\Feature\Guest;

use App\Models\BestAdvertiser;
use App\Models\BestAdvertiserSectionRank;
use App\Models\Category;
use App\Models\Listing;
use App\Models\SystemSetting;
use App\Models\User;

/**
 * @group guest
 * @group best-advertisers
 */
class BestAdvertiserTest extends GuestTestCase
{
    use CreatesTestData;

    /**
     * Test that guest can view best advertisers
     * 
     * **Validates: Requirements 2.1**
     */
    public function test_guest_can_view_best_advertisers(): void
    {
        // Create a category
        $category = $this->createActiveCategory(['slug' => 'test-section', 'name' => 'قسم تجريبي']);
        
        // Create an active user
        $user = User::factory()->create(['status' => 'active']);
        
        // Create a best advertiser
        $this->createBestAdvertiser($user, [$category->id]);
        
        // Create a valid listing for this user
        Listing::factory()->create([
            'category_id' => $category->id,
            'user_id' => $user->id,
            'status' => 'Valid',
            'expire_at' => now()->addDays(30),
        ]);

        $response = $this->guestGet('/api/the-best/test-section');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'advertisers' => [
                '*' => [
                    'id',
                    'user',
                    'listings',
                ]
            ]
        ]);
    }

    /**
     * Test that only active advertisers are returned
     * 
     * **Validates: Requirements 2.2**
     */
    public function test_only_active_advertisers_returned(): void
    {
        // Create a category
        $category = $this->createActiveCategory(['slug' => 'active-test', 'name' => 'اختبار النشطين']);
        
        // Create active user with active best advertiser
        $activeUser = User::factory()->create(['status' => 'active', 'name' => 'مستخدم نشط']);
        $this->createBestAdvertiser($activeUser, [$category->id], ['is_active' => true]);
        Listing::factory()->create([
            'category_id' => $category->id,
            'user_id' => $activeUser->id,
            'status' => 'Valid',
            'expire_at' => now()->addDays(30),
        ]);
        
        // Create inactive best advertiser
        $inactiveUser = User::factory()->create(['status' => 'active', 'name' => 'معلن غير نشط']);
        $this->createBestAdvertiser($inactiveUser, [$category->id], ['is_active' => false]);
        Listing::factory()->create([
            'category_id' => $category->id,
            'user_id' => $inactiveUser->id,
            'status' => 'Valid',
            'expire_at' => now()->addDays(30),
        ]);
        
        // Create best advertiser with inactive user
        $blockedUser = User::factory()->create(['status' => 'blocked', 'name' => 'مستخدم محظور']);
        $this->createBestAdvertiser($blockedUser, [$category->id], ['is_active' => true]);
        Listing::factory()->create([
            'category_id' => $category->id,
            'user_id' => $blockedUser->id,
            'status' => 'Valid',
            'expire_at' => now()->addDays(30),
        ]);

        $response = $this->guestGet('/api/the-best/active-test');

        $response->assertStatus(200);
        
        $data = $response->json('advertisers');
        
        // Only one active advertiser should be returned
        $this->assertCount(1, $data);
        $this->assertEquals('مستخدم نشط', $data[0]['user']['name']);
    }

    /**
     * Test that advertiser contains user info
     * 
     * **Validates: Requirements 2.3**
     */
    public function test_advertiser_contains_user_info(): void
    {
        // Create a category
        $category = $this->createActiveCategory(['slug' => 'user-info-test', 'name' => 'اختبار معلومات المستخدم']);
        
        // Create an active user
        $user = User::factory()->create(['status' => 'active', 'name' => 'محمد أحمد']);
        
        // Create a best advertiser
        $this->createBestAdvertiser($user, [$category->id]);
        
        // Create a valid listing
        Listing::factory()->create([
            'category_id' => $category->id,
            'user_id' => $user->id,
            'status' => 'Valid',
            'expire_at' => now()->addDays(30),
        ]);

        $response = $this->guestGet('/api/the-best/user-info-test');

        $response->assertStatus(200);
        
        $data = $response->json('advertisers');
        $this->assertGreaterThan(0, count($data));
        
        // Verify user info structure
        $advertiser = $data[0];
        $this->assertArrayHasKey('user', $advertiser);
        $this->assertArrayHasKey('id', $advertiser['user']);
        $this->assertArrayHasKey('name', $advertiser['user']);
        $this->assertEquals($user->id, $advertiser['user']['id']);
        $this->assertEquals('محمد أحمد', $advertiser['user']['name']);
    }

    /**
     * Test that advertiser contains listings array
     * 
     * **Validates: Requirements 2.4**
     */
    public function test_advertiser_contains_listings_array(): void
    {
        // Create a category
        $category = $this->createActiveCategory(['slug' => 'listings-array-test', 'name' => 'اختبار مصفوفة الإعلانات']);
        
        // Create an active user
        $user = User::factory()->create(['status' => 'active']);
        
        // Create a best advertiser
        $this->createBestAdvertiser($user, [$category->id]);
        
        // Create multiple valid listings
        Listing::factory()->create([
            'category_id' => $category->id,
            'user_id' => $user->id,
            'status' => 'Valid',
            'expire_at' => now()->addDays(30),
            'price' => 1000,
        ]);
        Listing::factory()->create([
            'category_id' => $category->id,
            'user_id' => $user->id,
            'status' => 'Valid',
            'expire_at' => now()->addDays(30),
            'price' => 2000,
        ]);

        $response = $this->guestGet('/api/the-best/listings-array-test');

        $response->assertStatus(200);
        
        $data = $response->json('advertisers');
        $this->assertGreaterThan(0, count($data));
        
        // Verify listings array structure
        $advertiser = $data[0];
        $this->assertArrayHasKey('listings', $advertiser);
        $this->assertIsArray($advertiser['listings']);
        $this->assertCount(2, $advertiser['listings']);
        
        // Verify listing contains required fields
        $listing = $advertiser['listings'][0];
        $this->assertArrayHasKey('id', $listing);
        $this->assertArrayHasKey('price', $listing);
        $this->assertArrayHasKey('attributes', $listing);
        $this->assertArrayHasKey('governorate', $listing);
        $this->assertArrayHasKey('city', $listing);
        $this->assertArrayHasKey('main_image_url', $listing);
        $this->assertArrayHasKey('category', $listing);
        $this->assertArrayHasKey('category_name', $listing);
    }

    /**
     * Test that empty advertisers returns empty array
     * 
     * **Validates: Requirements 2.5**
     */
    public function test_empty_advertisers_returns_empty_array(): void
    {
        // Create a category without any best advertisers
        $category = $this->createActiveCategory(['slug' => 'empty-test', 'name' => 'اختبار فارغ']);

        $response = $this->guestGet('/api/the-best/empty-test');

        $response->assertStatus(200);
        $response->assertJson(['advertisers' => []]);
        
        $data = $response->json('advertisers');
        $this->assertIsArray($data);
        $this->assertCount(0, $data);
    }

    /**
     * Test that max listings per advertiser is respected
     * 
     * **Validates: Requirements 2.6**
     */
    public function test_max_listings_per_advertiser_respected(): void
    {
        // Set max listings to 3
        SystemSetting::updateOrCreate(
            ['key' => 'featured_user_max_ads'],
            ['value' => '3', 'type' => 'integer']
        );
        
        // Create a category
        $category = $this->createActiveCategory(['slug' => 'max-listings-test', 'name' => 'اختبار الحد الأقصى']);
        
        // Create an active user
        $user = User::factory()->create(['status' => 'active']);
        
        // Create a best advertiser
        $this->createBestAdvertiser($user, [$category->id]);
        
        // Create 5 valid listings (more than the max)
        for ($i = 1; $i <= 5; $i++) {
            $this->createValidListing($category, [
                'user_id' => $user->id,
                'rank' => $i,
                'price' => $i * 1000,
            ]);
        }

        $response = $this->guestGet('/api/the-best/max-listings-test');

        $response->assertStatus(200);
        
        $data = $response->json('advertisers');
        $this->assertGreaterThan(0, count($data));
        
        // Verify only 3 listings are returned (the max)
        $advertiser = $data[0];
        $this->assertCount(3, $advertiser['listings']);
    }

    /**
     * Test that invalid section returns error
     * 
     * **Validates: Requirements 2.7**
     */
    public function test_invalid_section_returns_error(): void
    {
        $response = $this->guestGet('/api/the-best/invalid-section-slug-12345');

        // Should return 404 because Section::fromSlug() will throw ModelNotFoundException
        $response->assertStatus(404);
    }

    /**
     * Test that best advertiser listings contain required fields
     * 
     * **Validates: Requirements 3.1**
     */
    public function test_best_advertiser_listings_contain_required_fields(): void
    {
        // Create a category
        $category = $this->createActiveCategory(['slug' => 'required-fields-test', 'name' => 'اختبار الحقول المطلوبة']);
        
        // Create an active user
        $user = User::factory()->create(['status' => 'active']);
        
        // Create a best advertiser
        $this->createBestAdvertiser($user, [$category->id]);
        
        // Create a valid listing with all required data
        $this->createValidListing($category, [
            'user_id' => $user->id,
            'price' => 5000,
            'main_image' => 'listings/test-image.jpg',
        ]);

        $response = $this->guestGet('/api/the-best/required-fields-test');

        $response->assertStatus(200);
        
        $data = $response->json('advertisers');
        $this->assertGreaterThan(0, count($data));
        
        // Verify listing contains all required fields
        $listing = $data[0]['listings'][0];
        $this->assertArrayHasKey('id', $listing);
        $this->assertArrayHasKey('price', $listing);
        $this->assertArrayHasKey('attributes', $listing);
        $this->assertArrayHasKey('governorate', $listing);
        $this->assertArrayHasKey('city', $listing);
        $this->assertArrayHasKey('main_image_url', $listing);
        $this->assertArrayHasKey('category', $listing);
        $this->assertArrayHasKey('category_name', $listing);
        $this->assertArrayHasKey('rank', $listing);
        $this->assertArrayHasKey('views', $listing);
        
        // Verify types
        $this->assertIsInt($listing['id']);
        $this->assertIsNumeric($listing['price']);
        $this->assertIsArray($listing['attributes']);
    }

    /**
     * Test that only valid status listings are included
     * 
     * **Validates: Requirements 3.2**
     */
    public function test_only_valid_status_listings_included(): void
    {
        // Create a category
        $category = $this->createActiveCategory(['slug' => 'valid-status-test', 'name' => 'اختبار الحالة الصحيحة']);
        
        // Create an active user
        $user = User::factory()->create(['status' => 'active']);
        
        // Create a best advertiser
        $this->createBestAdvertiser($user, [$category->id]);
        
        // Create a Valid listing
        $this->createValidListing($category, [
            'user_id' => $user->id,
            'status' => 'Valid',
            'expire_at' => now()->addDays(30),
            'price' => 1000,
        ]);
        
        // Create listings with other statuses (should not appear)
        Listing::factory()->create([
            'category_id' => $category->id,
            'user_id' => $user->id,
            'status' => 'Pending',
            'price' => 2000,
        ]);
        
        Listing::factory()->create([
            'category_id' => $category->id,
            'user_id' => $user->id,
            'status' => 'Rejected',
            'price' => 3000,
        ]);
        
        // Create an expired listing (should not appear)
        Listing::factory()->create([
            'category_id' => $category->id,
            'user_id' => $user->id,
            'status' => 'Valid',
            'expire_at' => now()->subDays(1),
            'price' => 4000,
        ]);

        $response = $this->guestGet('/api/the-best/valid-status-test');

        $response->assertStatus(200);
        
        $data = $response->json('advertisers');
        $this->assertGreaterThan(0, count($data));
        
        // Verify only 1 listing is returned (the Valid one)
        $listings = $data[0]['listings'];
        $this->assertCount(1, $listings);
        $this->assertEquals(1000, $listings[0]['price']);
    }

    /**
     * Test that listings are ordered by rank then published_at
     * 
     * **Validates: Requirements 3.3**
     */
    public function test_listings_ordered_by_rank_then_published_at(): void
    {
        // Create a category
        $category = $this->createActiveCategory(['slug' => 'ordering-test', 'name' => 'اختبار الترتيب']);
        
        // Create an active user
        $user = User::factory()->create(['status' => 'active']);
        
        // Create a best advertiser
        $this->createBestAdvertiser($user, [$category->id]);
        
        // Create listings with different ranks and published_at dates
        $this->createValidListing($category, [
            'user_id' => $user->id,
            'rank' => 3,
            'published_at' => now()->subDays(1),
            'price' => 3000,
        ]);
        
        $this->createValidListing($category, [
            'user_id' => $user->id,
            'rank' => 1,
            'published_at' => now()->subDays(3),
            'price' => 1000,
        ]);
        
        $this->createValidListing($category, [
            'user_id' => $user->id,
            'rank' => 2,
            'published_at' => now()->subDays(2),
            'price' => 2000,
        ]);
        
        $this->createValidListing($category, [
            'user_id' => $user->id,
            'rank' => 1,
            'published_at' => now(),
            'price' => 1500,
        ]);

        $response = $this->guestGet('/api/the-best/ordering-test');

        $response->assertStatus(200);
        
        $data = $response->json('advertisers');
        $this->assertGreaterThan(0, count($data));
        
        $listings = $data[0]['listings'];
        $this->assertGreaterThanOrEqual(3, count($listings));
        
        // Verify ordering: rank ASC, then published_at DESC
        // First should be rank 1 with most recent published_at (price 1500)
        $this->assertEquals(1, $listings[0]['rank']);
        $this->assertEquals(1500, $listings[0]['price']);
        
        // Second should be rank 1 with older published_at (price 1000)
        $this->assertEquals(1, $listings[1]['rank']);
        $this->assertEquals(1000, $listings[1]['price']);
        
        // Third should be rank 2 (price 2000)
        $this->assertEquals(2, $listings[2]['rank']);
        $this->assertEquals(2000, $listings[2]['price']);
    }

    /**
     * Test that featured advertisers are ordered by section-specific ranks
     * and the same advertiser can have different positions across sections.
     */
    public function test_featured_advertisers_follow_section_specific_ordering_across_sections(): void
    {
        $cars = $this->createActiveCategory([
            'slug' => 'section-order-cars',
            'name' => 'سيارات',
            'sort_order' => 1,
        ]);
        $jobs = $this->createActiveCategory([
            'slug' => 'section-order-jobs',
            'name' => 'وظائف',
            'sort_order' => 2,
        ]);

        $firstUser = User::factory()->create([
            'status' => 'active',
            'name' => 'المعلن الأول',
        ]);
        $secondUser = User::factory()->create([
            'status' => 'active',
            'name' => 'المعلن الثاني',
        ]);
        $thirdUser = User::factory()->create([
            'status' => 'active',
            'name' => 'المعلن الثالث',
        ]);

        $firstAdvertiser = $this->createBestAdvertiser($firstUser, [$cars->id, $jobs->id], [
            'rank' => 3,
        ]);
        $secondAdvertiser = $this->createBestAdvertiser($secondUser, [$cars->id, $jobs->id], [
            'rank' => 1,
        ]);
        $thirdAdvertiser = $this->createBestAdvertiser($thirdUser, [$cars->id, $jobs->id], [
            'rank' => 2,
        ]);

        BestAdvertiserSectionRank::insert([
            [
                'best_advertiser_id' => $firstAdvertiser->id,
                'category_id' => $cars->id,
                'rank' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'best_advertiser_id' => $secondAdvertiser->id,
                'category_id' => $cars->id,
                'rank' => 2,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'best_advertiser_id' => $thirdAdvertiser->id,
                'category_id' => $cars->id,
                'rank' => 3,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'best_advertiser_id' => $thirdAdvertiser->id,
                'category_id' => $jobs->id,
                'rank' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'best_advertiser_id' => $firstAdvertiser->id,
                'category_id' => $jobs->id,
                'rank' => 2,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'best_advertiser_id' => $secondAdvertiser->id,
                'category_id' => $jobs->id,
                'rank' => 3,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        foreach ([
            [$cars, $firstUser, 1000],
            [$cars, $secondUser, 2000],
            [$cars, $thirdUser, 3000],
            [$jobs, $firstUser, 4000],
            [$jobs, $secondUser, 5000],
            [$jobs, $thirdUser, 6000],
        ] as [$category, $user, $price]) {
            $this->createValidListing($category, [
                'user_id' => $user->id,
                'price' => $price,
            ]);
        }

        $carsResponse = $this->guestGet('/api/the-best/section-order-cars');
        $jobsResponse = $this->guestGet('/api/the-best/section-order-jobs');

        $carsResponse->assertOk();
        $jobsResponse->assertOk();

        $carsAdvertisers = $carsResponse->json('advertisers');
        $jobsAdvertisers = $jobsResponse->json('advertisers');

        $this->assertCount(3, $carsAdvertisers);
        $this->assertCount(3, $jobsAdvertisers);

        $this->assertSame('المعلن الأول', $carsAdvertisers[0]['user']['name']);
        $this->assertSame('المعلن الثاني', $carsAdvertisers[1]['user']['name']);
        $this->assertSame('المعلن الثالث', $carsAdvertisers[2]['user']['name']);

        $this->assertSame('المعلن الثالث', $jobsAdvertisers[0]['user']['name']);
        $this->assertSame('المعلن الأول', $jobsAdvertisers[1]['user']['name']);
        $this->assertSame('المعلن الثاني', $jobsAdvertisers[2]['user']['name']);
    }

    /**
     * Test that category info is included
     * 
     * **Validates: Requirements 3.4**
     */
    public function test_category_info_included(): void
    {
        // Create a category with specific slug and name
        $category = $this->createActiveCategory([
            'slug' => 'category-info-test',
            'name' => 'معلومات القسم'
        ]);
        
        // Create an active user
        $user = User::factory()->create(['status' => 'active']);
        
        // Create a best advertiser
        $this->createBestAdvertiser($user, [$category->id]);
        
        // Create a valid listing
        $this->createValidListing($category, [
            'user_id' => $user->id,
            'price' => 7500,
        ]);

        $response = $this->guestGet('/api/the-best/category-info-test');

        $response->assertStatus(200);
        
        $data = $response->json('advertisers');
        $this->assertGreaterThan(0, count($data));
        
        // Verify category information is included
        $listing = $data[0]['listings'][0];
        $this->assertArrayHasKey('category', $listing);
        $this->assertArrayHasKey('category_name', $listing);
        $this->assertEquals('category-info-test', $listing['category']);
        $this->assertEquals('معلومات القسم', $listing['category_name']);
    }

    /**
     * Test that global image settings are respected
     * 
     * **Validates: Requirements 3.5**
     */
    public function test_global_image_settings_respected(): void
    {
        // Create a category with global image active
        $category = $this->createActiveCategory([
            'slug' => 'global-image-test',
            'name' => 'اختبار الصورة الموحدة',
            'is_global_image_active' => true,
            'global_image_url' => 'categories/global-image.jpg',
        ]);
        
        // Create an active user
        $user = User::factory()->create(['status' => 'active']);
        
        // Create a best advertiser
        $this->createBestAdvertiser($user, [$category->id]);
        
        // Create a valid listing with its own image
        $this->createValidListing($category, [
            'user_id' => $user->id,
            'main_image' => 'listings/listing-image.jpg',
            'price' => 9000,
        ]);

        $response = $this->guestGet('/api/the-best/global-image-test');

        $response->assertStatus(200);
        
        $data = $response->json('advertisers');
        $this->assertGreaterThan(0, count($data));
        
        // Verify global image settings are included
        $listing = $data[0]['listings'][0];
        $this->assertArrayHasKey('is_global_image_active', $listing);
        $this->assertArrayHasKey('global_image_url', $listing);
        $this->assertTrue($listing['is_global_image_active']);
        $this->assertEquals('categories/global-image.jpg', $listing['global_image_url']);
    }

    /**
     * Test that no valid listings returns empty array
     * 
     * **Validates: Requirements 3.6**
     */
    public function test_no_valid_listings_returns_empty_array(): void
    {
        // Create a category
        $category = $this->createActiveCategory(['slug' => 'no-listings-test', 'name' => 'اختبار بدون إعلانات']);
        
        // Create an active user
        $user = User::factory()->create(['status' => 'active']);
        
        // Create a best advertiser
        $this->createBestAdvertiser($user, [$category->id]);
        
        // Create only invalid listings
        Listing::factory()->create([
            'category_id' => $category->id,
            'user_id' => $user->id,
            'status' => 'Pending',
            'price' => 1000,
        ]);
        
        Listing::factory()->create([
            'category_id' => $category->id,
            'user_id' => $user->id,
            'status' => 'Valid',
            'expire_at' => now()->subDays(1), // Expired
            'price' => 2000,
        ]);

        $response = $this->guestGet('/api/the-best/no-listings-test');

        $response->assertStatus(200);
        
        $data = $response->json('advertisers');
        $this->assertGreaterThan(0, count($data));
        
        // Verify listings array is empty
        $listings = $data[0]['listings'];
        $this->assertIsArray($listings);
        $this->assertCount(0, $listings);
    }
}
