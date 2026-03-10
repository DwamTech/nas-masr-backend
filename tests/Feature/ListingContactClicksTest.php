<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ListingContactClicksTest extends TestCase
{
    use RefreshDatabase;

    public function test_contact_click_endpoint_increments_selected_counter(): void
    {
        $category = Category::factory()->create([
            'slug' => 'cars',
            'is_active' => true,
        ]);
        $owner = User::factory()->create();
        $listing = $this->createListing($owner->id, $category->id, [
            'whatsapp_clicks' => 0,
            'call_clicks' => 0,
        ]);

        $this->postJson("/api/v1/cars/listings/{$listing->id}/contact-click", [
            'type' => 'whatsapp',
        ])->assertOk()
          ->assertJsonPath('whatsapp_clicks', 1)
          ->assertJsonPath('call_clicks', 0);

        $this->postJson("/api/v1/cars/listings/{$listing->id}/contact-click", [
            'type' => 'call',
        ])->assertOk()
          ->assertJsonPath('whatsapp_clicks', 1)
          ->assertJsonPath('call_clicks', 1);
    }

    public function test_my_ads_returns_contact_click_counters_for_owner(): void
    {
        $category = Category::factory()->create([
            'slug' => 'cars',
            'is_active' => true,
        ]);
        $owner = User::factory()->create();
        $this->createListing($owner->id, $category->id, [
            'whatsapp_clicks' => 7,
            'call_clicks' => 3,
        ]);

        Sanctum::actingAs($owner);

        $this->getJson('/api/my-ads?category_slug=cars')
            ->assertOk()
            ->assertJsonPath('data.0.whatsapp_clicks', 7)
            ->assertJsonPath('data.0.call_clicks', 3);
    }

    public function test_public_user_details_hide_counters_but_admin_can_see_them(): void
    {
        $category = Category::factory()->create([
            'slug' => 'cars',
            'is_active' => true,
        ]);
        $owner = User::factory()->create();
        $listing = $this->createListing($owner->id, $category->id, [
            'whatsapp_clicks' => 5,
            'call_clicks' => 2,
        ]);

        $this->getJson("/api/users/{$owner->id}?category_slug=cars")
            ->assertOk()
            ->assertJsonMissingPath('listings.0.whatsapp_clicks')
            ->assertJsonMissingPath('listings.0.call_clicks')
            ->assertJsonMissingPath('user.whatsapp_clicks')
            ->assertJsonMissingPath('user.call_clicks');

        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        $this->getJson("/api/admin/users/{$owner->id}?category_slug=cars")
            ->assertOk()
            ->assertJsonPath('listings.0.id', $listing->id)
            ->assertJsonPath('listings.0.whatsapp_clicks', 5)
            ->assertJsonPath('listings.0.call_clicks', 2)
            ->assertJsonPath('user.whatsapp_clicks', 5)
            ->assertJsonPath('user.call_clicks', 2);
    }

    public function test_admin_users_summary_includes_aggregated_contact_click_counters(): void
    {
        $category = Category::factory()->create([
            'slug' => 'cars',
            'is_active' => true,
        ]);
        $user = User::factory()->create();
        $this->createListing($user->id, $category->id, [
            'whatsapp_clicks' => 4,
            'call_clicks' => 1,
        ]);
        $this->createListing($user->id, $category->id, [
            'whatsapp_clicks' => 6,
            'call_clicks' => 9,
        ]);

        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/users-summary?q=' . $user->phone)
            ->assertOk()
            ->assertJsonPath('users.0.id', $user->id)
            ->assertJsonPath('users.0.whatsapp_clicks', 10)
            ->assertJsonPath('users.0.call_clicks', 10);
    }

    private function createListing(int $userId, int $categoryId, array $overrides = []): Listing
    {
        return Listing::create(array_merge([
            'category_id' => $categoryId,
            'user_id' => $userId,
            'title' => 'Test Listing',
            'price' => 1000,
            'currency' => 'EGP',
            'description' => 'Test Description',
            'status' => 'Valid',
            'published_at' => now(),
            'plan_type' => 'free',
            'views' => 0,
            'whatsapp_clicks' => 0,
            'call_clicks' => 0,
            'rank' => 0,
            'admin_approved' => true,
            'isPayment' => false,
        ], $overrides));
    }
}
