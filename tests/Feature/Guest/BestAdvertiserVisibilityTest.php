<?php

namespace Tests\Feature\Guest;

use App\Models\Listing;
use App\Models\User;

/**
 * @group guest
 * @group best-advertisers
 * @group featured-visibility
 */
class BestAdvertiserVisibilityTest extends GuestTestCase
{
    use CreatesTestData;

    public function test_disabled_section_skips_best_advertisers_and_returns_direct_ads_flag(): void
    {
        $category = $this->createActiveCategory([
            'slug' => 'cars-direct',
            'name' => 'سيارات مباشر',
            'show_featured_advertisers' => false,
        ]);

        $user = User::factory()->create([
            'status' => 'active',
            'name' => 'معلن سيارات',
        ]);

        $this->createBestAdvertiser($user, [$category->id]);

        Listing::factory()->create([
            'category_id' => $category->id,
            'user_id' => $user->id,
            'status' => 'Valid',
            'expire_at' => now()->addDays(30),
        ]);

        $this->guestGet('/api/the-best/cars-direct')
            ->assertOk()
            ->assertJson([
                'show_featured_advertisers' => false,
                'advertisers' => [],
            ]);
    }

    public function test_enabled_section_keeps_existing_best_advertisers_behavior(): void
    {
        $category = $this->createActiveCategory([
            'slug' => 'cars-featured',
            'name' => 'سيارات مميزة',
            'show_featured_advertisers' => true,
        ]);

        $user = User::factory()->create([
            'status' => 'active',
            'name' => 'معلن مميز',
        ]);

        $this->createBestAdvertiser($user, [$category->id]);

        Listing::factory()->create([
            'category_id' => $category->id,
            'user_id' => $user->id,
            'status' => 'Valid',
            'expire_at' => now()->addDays(30),
        ]);

        $response = $this->guestGet('/api/the-best/cars-featured')
            ->assertOk();

        $response->assertJsonMissing([
            'show_featured_advertisers' => false,
        ]);
        $this->assertCount(1, $response->json('advertisers'));
    }
}
