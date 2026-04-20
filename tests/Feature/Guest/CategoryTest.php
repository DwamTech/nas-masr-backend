<?php

namespace Tests\Feature\Guest;

use App\Models\Category;

/**
 * @group guest
 * @group categories
 */
class CategoryTest extends GuestTestCase
{
    use CreatesTestData;

    /**
     * Test that guest can view categories
     * 
     * **Validates: Requirements 1.1**
     */
    public function test_guest_can_view_categories(): void
    {
        // The TestDataSeeder creates categories
        // Let's verify we have at least some categories
        $response = $this->guestGet('/api/categories');

        $response->assertStatus(200);
        $response->assertJsonStructure(['data']);
        $this->assertGreaterThanOrEqual(1, count($response->json('data')));
    }

    /**
     * Test that only active categories are returned
     * 
     * **Validates: Requirements 1.2**
     */
    public function test_only_active_categories_are_returned(): void
    {
        // Create some active categories with unique slugs
        $this->createActiveCategory(['name' => 'نشط 1', 'slug' => 'active-test-1']);
        $this->createActiveCategory(['name' => 'نشط 2', 'slug' => 'active-test-2']);
        
        // Create inactive categories
        Category::factory()->create(['is_active' => false, 'name' => 'غير نشط 1', 'slug' => 'inactive-1']);
        Category::factory()->create(['is_active' => false, 'name' => 'غير نشط 2', 'slug' => 'inactive-2']);

        $response = $this->guestGet('/api/categories');

        $response->assertStatus(200);
        
        // Verify all returned categories are active
        $data = $response->json('data');
        $this->assertGreaterThan(0, count($data));
        foreach ($data as $category) {
            $this->assertTrue($category['is_active']);
        }
        
        // Verify inactive categories are not in the response
        $slugs = array_column($data, 'slug');
        $this->assertNotContains('inactive-1', $slugs);
        $this->assertNotContains('inactive-2', $slugs);
    }

    /**
     * Test that categories contain required fields
     * 
     * **Validates: Requirements 1.3**
     */
    public function test_categories_contain_required_fields(): void
    {
        // Ensure we have at least one category
        $this->createActiveCategory(['name' => 'تست', 'slug' => 'test-category-fields', 'icon' => 'test.png']);
        
        $response = $this->guestGet('/api/categories');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'slug',
                    'name',
                    'icon_url',
                    'is_active',
                    'show_featured_advertisers',
                ]
            ]
        ]);

        // Verify at least one category has all required fields
        $data = $response->json('data');
        $this->assertGreaterThan(0, count($data));
        $this->assertArrayHasKey('id', $data[0]);
        $this->assertArrayHasKey('slug', $data[0]);
        $this->assertArrayHasKey('name', $data[0]);
        $this->assertArrayHasKey('icon_url', $data[0]);
        $this->assertArrayHasKey('is_active', $data[0]);
        $this->assertArrayHasKey('show_featured_advertisers', $data[0]);
    }

    /**
     * Test that empty categories returns empty array
     * 
     * **Validates: Requirements 1.4**
     */
    public function test_empty_categories_returns_empty_array(): void
    {
        // Delete all categories to test empty state
        Category::query()->delete();

        $response = $this->guestGet('/api/categories');

        $response->assertStatus(200);
        $response->assertJson(['data' => []]);
        $data = $response->json('data');
        $this->assertIsArray($data);
        $this->assertCount(0, $data);
    }

    /**
     * Test that categories are ordered by sort_order field
     * 
     * **Validates: Requirements 1.5**
     */
    public function test_categories_are_ordered_by_sort_order(): void
    {
        // Delete existing categories and create new ones with specific sort orders
        Category::query()->delete();
        
        $this->createActiveCategory(['name' => 'ثالث', 'slug' => 'third', 'sort_order' => 30]);
        $this->createActiveCategory(['name' => 'أول', 'slug' => 'first', 'sort_order' => 10]);
        $this->createActiveCategory(['name' => 'ثاني', 'slug' => 'second', 'sort_order' => 20]);

        $response = $this->guestGet('/api/categories');

        $response->assertStatus(200);
        
        $data = $response->json('data');
        
        // Verify ordering by sort_order
        $this->assertEquals('first', $data[0]['slug']);
        $this->assertEquals('second', $data[1]['slug']);
        $this->assertEquals('third', $data[2]['slug']);
        
        // Verify sort_order values are in ascending order
        $this->assertEquals(10, $data[0]['sort_order']);
        $this->assertEquals(20, $data[1]['sort_order']);
        $this->assertEquals(30, $data[2]['sort_order']);
    }
}
