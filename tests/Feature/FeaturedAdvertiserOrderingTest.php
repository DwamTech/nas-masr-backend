<?php

namespace Tests\Feature;

use App\Models\BestAdvertiser;
use App\Models\BestAdvertiserSectionRank;
use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FeaturedAdvertiserOrderingTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_and_authorized_employee_can_read_featured_sections_summary(): void
    {
        [$cars, $jobs] = $this->seedSections();
        [$admin, $employee] = $this->authorizedUsers();

        $this->createFeaturedAdvertiser('أحمد', '01000000011', [$cars->id, $jobs->id], 1, [
            $cars->id => 1,
            $jobs->id => 1,
        ]);
        $this->createFeaturedAdvertiser('محمد', '01000000012', [$cars->id], 2, [
            $cars->id => 2,
        ]);

        $inactiveUser = User::factory()->create([
            'name' => 'موقوف',
            'phone' => '01000000013',
            'status' => 'banned',
        ]);

        BestAdvertiser::create([
            'user_id' => $inactiveUser->id,
            'category_ids' => [$cars->id],
            'rank' => 3,
            'is_active' => true,
            'max_listings' => 4,
        ]);

        $this->actingAs($admin)
            ->getJson('/api/admin/featured/sections')
            ->assertOk()
            ->assertJsonPath('sections.0.slug', 'cars')
            ->assertJsonPath('sections.0.featured_advertisers_count', 2)
            ->assertJsonPath('sections.1.slug', 'jobs')
            ->assertJsonPath('sections.1.featured_advertisers_count', 1);

        $this->actingAs($employee)
            ->getJson('/api/admin/featured/sections')
            ->assertOk()
            ->assertJsonPath('sections.0.featured_advertisers_count', 2)
            ->assertJsonPath('sections.1.featured_advertisers_count', 1);

        $this->assertDatabaseHas('best_advertiser_section_ranks', [
            'category_id' => $cars->id,
            'rank' => 1,
        ]);
    }

    public function test_employee_without_permission_is_blocked_from_featured_sections_endpoints(): void
    {
        [$cars] = $this->seedSections();
        $employee = User::factory()->create([
            'role' => 'employee',
            'status' => 'active',
            'allowed_dashboard_pages' => ['dashboard.home'],
        ]);

        $this->createFeaturedAdvertiser('سارة', '01000000021', [$cars->id], 1);

        $this->actingAs($employee)
            ->getJson('/api/admin/featured/sections')
            ->assertStatus(403);

        $this->actingAs($employee)
            ->getJson('/api/admin/featured/sections/cars/advertisers')
            ->assertStatus(403);
    }

    public function test_section_advertisers_endpoint_returns_sorted_advertisers_for_requested_section(): void
    {
        [$cars, $jobs] = $this->seedSections();
        [$admin] = $this->authorizedUsers();

        $first = $this->createFeaturedAdvertiser('علي', '01000000031', [$cars->id], 2, [
            $cars->id => 1,
        ]);
        $second = $this->createFeaturedAdvertiser('منى', '01000000032', [$cars->id, $jobs->id], 4, [
            $cars->id => 2,
            $jobs->id => 2,
        ]);
        $this->createFeaturedAdvertiser('قسم آخر', '01000000033', [$jobs->id], 3, [
            $jobs->id => 1,
        ]);

        $this->actingAs($admin)
            ->getJson('/api/admin/featured/sections/cars/advertisers')
            ->assertOk()
            ->assertJsonPath('section.slug', 'cars')
            ->assertJsonPath('advertisers.0.id', $first->id)
            ->assertJsonPath('advertisers.1.id', $second->id)
            ->assertJsonPath('advertisers.1.categories_count', 2);
    }

    public function test_reordering_section_advertisers_reorders_only_that_sections_slots_and_normalizes_ranks(): void
    {
        [$cars, $jobs] = $this->seedSections();
        [$admin] = $this->authorizedUsers();

        $firstCars = $this->createFeaturedAdvertiser('الأول', '01000000041', [$cars->id], 1, [
            $cars->id => 1,
        ]);
        $jobsOnly = $this->createFeaturedAdvertiser('الوظائف', '01000000042', [$jobs->id], 2, [
            $jobs->id => 1,
        ]);
        $secondCars = $this->createFeaturedAdvertiser('الثاني', '01000000043', [$cars->id, $jobs->id], 3, [
            $cars->id => 2,
            $jobs->id => 2,
        ]);
        $lastJobs = $this->createFeaturedAdvertiser('الأخير', '01000000044', [$jobs->id], 4, [
            $jobs->id => 3,
        ]);

        $this->actingAs($admin)
            ->postJson('/api/admin/featured/sections/cars/reorder', [
                'advertiser_ids' => [$secondCars->id, $firstCars->id],
            ])
            ->assertOk()
            ->assertJsonPath('data.updated_count', 2);

        $this->assertSame(3, $secondCars->fresh()->rank);
        $this->assertSame(2, $jobsOnly->fresh()->rank);
        $this->assertSame(1, $firstCars->fresh()->rank);
        $this->assertSame(4, $lastJobs->fresh()->rank);

        $this->assertDatabaseHas('best_advertiser_section_ranks', [
            'best_advertiser_id' => $secondCars->id,
            'category_id' => $cars->id,
            'rank' => 1,
        ]);
        $this->assertDatabaseHas('best_advertiser_section_ranks', [
            'best_advertiser_id' => $firstCars->id,
            'category_id' => $cars->id,
            'rank' => 2,
        ]);
        $this->assertDatabaseHas('best_advertiser_section_ranks', [
            'best_advertiser_id' => $jobsOnly->id,
            'category_id' => $jobs->id,
            'rank' => 1,
        ]);
        $this->assertDatabaseHas('best_advertiser_section_ranks', [
            'best_advertiser_id' => $secondCars->id,
            'category_id' => $jobs->id,
            'rank' => 2,
        ]);
        $this->assertDatabaseHas('best_advertiser_section_ranks', [
            'best_advertiser_id' => $lastJobs->id,
            'category_id' => $jobs->id,
            'rank' => 3,
        ]);
    }

    public function test_reordering_requires_full_section_membership_payload(): void
    {
        [$cars] = $this->seedSections();
        [$admin] = $this->authorizedUsers();

        $first = $this->createFeaturedAdvertiser('أول', '01000000051', [$cars->id], 1);
        $this->createFeaturedAdvertiser('ثان', '01000000052', [$cars->id], 2);

        $this->actingAs($admin)
            ->postJson('/api/admin/featured/sections/cars/reorder', [
                'advertiser_ids' => [$first->id],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('advertiser_ids');
    }

    private function seedSections(): array
    {
        $cars = Category::create([
            'name' => 'سيارات',
            'slug' => 'cars',
            'title' => 'سيارات',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $jobs = Category::create([
            'name' => 'وظائف',
            'slug' => 'jobs',
            'title' => 'وظائف',
            'is_active' => true,
            'sort_order' => 2,
        ]);

        return [$cars, $jobs];
    }

    private function authorizedUsers(): array
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
            'phone' => '01000000001',
        ]);

        $employee = User::factory()->create([
            'role' => 'employee',
            'status' => 'active',
            'phone' => '01000000002',
            'allowed_dashboard_pages' => ['categories.featured_advertisers'],
        ]);

        return [$admin, $employee];
    }

    private function createFeaturedAdvertiser(
        string $name,
        string $phone,
        array $categoryIds,
        int $rank,
        array $sectionRankMap = []
    ): BestAdvertiser
    {
        $user = User::factory()->create([
            'name' => $name,
            'phone' => $phone,
            'status' => 'active',
        ]);

        $advertiser = BestAdvertiser::create([
            'user_id' => $user->id,
            'category_ids' => $categoryIds,
            'rank' => $rank,
            'is_active' => true,
            'max_listings' => 8,
        ]);

        foreach ($sectionRankMap as $categoryId => $sectionRank) {
            BestAdvertiserSectionRank::create([
                'best_advertiser_id' => $advertiser->id,
                'category_id' => (int) $categoryId,
                'rank' => (int) $sectionRank,
            ]);
        }

        return $advertiser;
    }
}
