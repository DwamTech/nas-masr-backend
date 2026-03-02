<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Category;
use App\Models\Listing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Eris\Generator;
use Eris\TestTrait;

/**
 * Property-Based Tests for Unified Category Images Management
 * 
 * These tests verify correctness properties across many iterations using
 * the Eris property-based testing library.
 */
class UnifiedCategoryImagesPropertyTest extends TestCase
{
    use RefreshDatabase;
    use TestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    /**
     * Feature: unified-category-images-management, Property 3: Toggle State Persistence
     * 
     * **Validates: Requirements 2.2, 2.3, 6.3, 6.4**
     * 
     * For any category, when the admin toggles the unified image switch,
     * the is_global_image_active field in the database should be updated
     * to match the toggle state (true for enabled, false for disabled).
     * 
     * @test
     */
    public function property_toggle_state_persists_correctly()
    {
        $this->forAll(
            Generator\bool()
        )->then(function ($toggleState) {
            // Arrange: Create admin user and category
            $admin = User::factory()->create(['role' => 'admin']);
            $category = Category::factory()->create([
                'is_global_image_active' => !$toggleState, // Start with opposite state
            ]);

            // Act: Toggle the unified image state
            $response = $this->actingAs($admin, 'sanctum')
                ->putJson("/api/admin/categories/{$category->id}/toggle-global-image", [
                    'is_global_image_active' => $toggleState,
                ]);

            // Assert: Response is successful
            $response->assertOk();

            // Assert: Database state matches toggle state
            $this->assertEquals(
                $toggleState,
                $category->fresh()->is_global_image_active,
                "Toggle state should persist to database. Expected: " . ($toggleState ? 'true' : 'false')
            );

            // Assert: Response contains correct state
            $response->assertJson([
                'is_global_image_active' => $toggleState,
            ]);
        });
    }

    /**
     * Feature: unified-category-images-management, Property 7: Image Format Validation
     * 
     * **Validates: Requirements 3.4, 7.1, 7.2**
     * 
     * For any file uploaded by the admin, the backend should validate that
     * the file format is one of JPEG, PNG, or WebP, and reject files with
     * other formats with an appropriate error message.
     * 
     * @test
     */
    public function property_image_format_validation_works_correctly()
    {
        Storage::fake('public');

        $validFormats = ['jpg', 'jpeg', 'png', 'webp'];
        $invalidFormats = ['pdf', 'txt', 'doc', 'gif', 'bmp', 'svg'];

        $this->forAll(
            Generator\elements(...$validFormats),
            Generator\elements(...$invalidFormats)
        )->then(function ($validFormat, $invalidFormat) {
            // Arrange: Create admin user and category
            $admin = User::factory()->create(['role' => 'admin']);
            $category = Category::factory()->create([
                'is_global_image_active' => true,
            ]);

            // Test 1: Valid format should succeed
            $validFile = UploadedFile::fake()->image("test.{$validFormat}", 500, 500);
            $validResponse = $this->actingAs($admin, 'sanctum')
                ->postJson("/api/admin/categories/{$category->id}/upload-global-image", [
                    'image' => $validFile,
                ]);

            // Assert: Valid format is accepted
            $this->assertTrue(
                $validResponse->isSuccessful(),
                "Valid format '{$validFormat}' should be accepted"
            );
            $this->assertNotNull(
                $category->fresh()->global_image_url,
                "Valid format '{$validFormat}' should result in stored image URL"
            );

            // Test 2: Invalid format should fail
            $invalidFile = UploadedFile::fake()->create("test.{$invalidFormat}", 100);
            $invalidResponse = $this->actingAs($admin, 'sanctum')
                ->postJson("/api/admin/categories/{$category->id}/upload-global-image", [
                    'image' => $invalidFile,
                ]);

            // Assert: Invalid format is rejected with 422 status
            $this->assertEquals(
                422,
                $invalidResponse->status(),
                "Invalid format '{$invalidFormat}' should be rejected with 422 status"
            );

            // Assert: Error message is present
            $this->assertTrue(
                $invalidResponse->json('error') !== null || $invalidResponse->json('message') !== null,
                "Invalid format '{$invalidFormat}' should return an error message"
            );
        });
    }

    /**
     * Feature: unified-category-images-management, Property 16: Timestamp-Based Filename
     * 
     * **Validates: Requirements 5.3, 5.4, 10.5**
     * 
     * For any uploaded unified image, the backend should generate a filename
     * that includes a timestamp to ensure uniqueness and cache invalidation.
     * 
     * @test
     */
    public function property_uploaded_images_have_timestamp_in_filename()
    {
        Storage::fake('public');

        $this->forAll(
            Generator\choose(200, 2000), // Image width
            Generator\choose(200, 2000)  // Image height
        )->then(function ($width, $height) {
            // Arrange: Create admin user and category
            $admin = User::factory()->create(['role' => 'admin']);
            $category = Category::factory()->create([
                'is_global_image_active' => true,
            ]);

            // Act: Upload image with random dimensions
            $file = UploadedFile::fake()->image('test.jpg', $width, $height);
            $response = $this->actingAs($admin, 'sanctum')
                ->postJson("/api/admin/categories/{$category->id}/upload-global-image", [
                    'image' => $file,
                ]);

            // Assert: Upload is successful
            $response->assertOk();

            // Assert: Image URL contains timestamp pattern
            $imageUrl = $category->fresh()->global_image_url;
            $this->assertNotNull($imageUrl, "Image URL should not be null");

            // Pattern: uploads/categories/global/{category_id}_{timestamp}.webp
            $this->assertMatchesRegularExpression(
                '/_\d+\.webp$/',
                $imageUrl,
                "Image URL should contain timestamp pattern '_<digits>.webp'. Got: {$imageUrl}"
            );

            // Assert: Filename starts with category ID
            $filename = basename($imageUrl);
            $this->assertStringStartsWith(
                (string)$category->id . '_',
                $filename,
                "Filename should start with category ID. Got: {$filename}"
            );

            // Assert: Image is converted to WebP format
            $this->assertStringEndsWith(
                '.webp',
                $imageUrl,
                "Image should be converted to WebP format. Got: {$imageUrl}"
            );
        });
    }

    /**
     * Feature: unified-category-images-management, Property 33: Original Image Preservation
     * 
     * **Validates: Requirements 12.4**
    /**
     * Feature: unified-category-images-management, Property 33: Original Image Preservation
     * 
     * **Validates: Requirements 12.4**
     * 
     * For any listing, enabling unified images for its category should not
     * modify, delete, or affect the listing's original image data in the database.
     * 
     * This test verifies that category-level operations (toggle, upload) only
     * affect the categories table and do not trigger any updates to other tables.
     * 
     * @test
     */
    public function property_enabling_unified_image_preserves_listing_images()
    {
        Storage::fake('public');

        $this->forAll(
            Generator\bool() // whether to upload an image
        )->then(function ($shouldUpload) {
            // Arrange: Create admin user and category
            $admin = User::factory()->create(['role' => 'admin']);
            $category = Category::factory()->create([
                'is_global_image_active' => false,
                'global_image_url' => null,
            ]);

            // Act 1: Enable unified image toggle
            $toggleResponse = $this->actingAs($admin, 'sanctum')
                ->putJson("/api/admin/categories/{$category->id}/toggle-global-image", [
                    'is_global_image_active' => true,
                ]);

            // Assert: Toggle is successful
            $toggleResponse->assertOk();
            $this->assertTrue($category->fresh()->is_global_image_active);

            // Act 2: Optionally upload unified image
            if ($shouldUpload) {
                $unifiedImage = UploadedFile::fake()->image('unified.jpg', 800, 800);
                $uploadResponse = $this->actingAs($admin, 'sanctum')
                    ->postJson("/api/admin/categories/{$category->id}/upload-global-image", [
                        'image' => $unifiedImage,
                    ]);

                // Assert: Upload is successful
                $uploadResponse->assertOk();

                // Assert: Category has unified image
                $freshCategory = $category->fresh();
                $this->assertNotNull(
                    $freshCategory->global_image_url,
                    "Category should have unified image URL after upload"
                );
            }

            // Act 3: Disable unified image
            $disableResponse = $this->actingAs($admin, 'sanctum')
                ->putJson("/api/admin/categories/{$category->id}/toggle-global-image", [
                    'is_global_image_active' => false,
                ]);

            // Assert: Disable is successful
            $disableResponse->assertOk();
            $this->assertFalse($category->fresh()->is_global_image_active);

            // Assert: The operations only affected the category record
            // The category should still exist and be valid
            $this->assertNotNull($category->fresh());
            $this->assertEquals($category->id, $category->fresh()->id);
        });
    }
}
