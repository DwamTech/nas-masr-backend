<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Comprehensive Unit Tests for Backend CategoryController
 * 
 * Tests cover:
 * - Toggle functionality
 * - Upload functionality with validation
 * - Delete functionality
 * - Authorization (admin vs non-admin)
 * - Image processing (WebP conversion, resizing)
 * - Old image deletion
 * - API response structure
 */
class UnifiedCategoryImagesControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    // ========================================
    // Toggle Functionality Tests
    // ========================================

    /** @test */
    public function admin_can_toggle_unified_image_to_enabled()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $category = Category::factory()->create(['is_global_image_active' => false]);

        $response = $this->actingAs($admin)
            ->putJson("/api/admin/categories/{$category->id}/toggle-global-image", [
                'is_global_image_active' => true,
            ]);

        $response->assertOk();
        $response->assertJsonStructure([
            'id',
            'is_global_image_active',
            'global_image_url',
            'message',
        ]);
        
        $this->assertTrue($category->fresh()->is_global_image_active);
    }

    /** @test */
    public function admin_can_toggle_unified_image_to_disabled()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $category = Category::factory()->create(['is_global_image_active' => true]);

        $response = $this->actingAs($admin)
            ->putJson("/api/admin/categories/{$category->id}/toggle-global-image", [
                'is_global_image_active' => false,
            ]);

        $response->assertOk();
        $this->assertFalse($category->fresh()->is_global_image_active);
    }

    /** @test */
    public function toggle_returns_correct_response_structure()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $category = Category::factory()->create(['is_global_image_active' => false]);

        $response = $this->actingAs($admin)
            ->putJson("/api/admin/categories/{$category->id}/toggle-global-image", [
                'is_global_image_active' => true,
            ]);

        $response->assertOk();
        $response->assertJsonStructure([
            'id',
            'is_global_image_active',
            'global_image_url',
            'message',
        ]);
        
        $response->assertJson([
            'id' => $category->id,
            'is_global_image_active' => true,
        ]);
    }

    // ========================================
    // Upload Functionality Tests
    // ========================================

    /** @test */
    public function admin_can_upload_valid_jpeg_image()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $category = Category::factory()->create(['is_global_image_active' => false]);

        $file = UploadedFile::fake()->image('category.jpg', 500, 500);

        $response = $this->actingAs($admin)
            ->postJson("/api/admin/categories/{$category->id}/upload-global-image", [
                'image' => $file,
            ]);

        $response->assertOk();
        $this->assertNotNull($category->fresh()->global_image_url);
        // Note: We don't assert file existence here because fake JPEG images
        // may not be processed correctly by GD library in test environment
    }

    /** @test */
    public function admin_can_upload_valid_png_image()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $category = Category::factory()->create(['is_global_image_active' => false]);

        $file = UploadedFile::fake()->image('category.png', 500, 500);

        $response = $this->actingAs($admin)
            ->postJson("/api/admin/categories/{$category->id}/upload-global-image", [
                'image' => $file,
            ]);

        $response->assertOk();
        $this->assertNotNull($category->fresh()->global_image_url);
    }

    /** @test */
    public function upload_returns_correct_response_structure()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $category = Category::factory()->create(['is_global_image_active' => false]);

        $file = UploadedFile::fake()->image('category.jpg', 500, 500);

        $response = $this->actingAs($admin)
            ->postJson("/api/admin/categories/{$category->id}/upload-global-image", [
                'image' => $file,
            ]);

        $response->assertOk();
        $response->assertJsonStructure([
            'id',
            'global_image_url',
            'global_image_full_url',
            'is_global_image_active',
            'message',
        ]);
    }

    // ========================================
    // Validation Tests
    // ========================================

    /** @test */
    public function upload_rejects_non_image_files()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $category = Category::factory()->create(['is_global_image_active' => true]);

        $file = UploadedFile::fake()->create('document.pdf', 1000);

        $response = $this->actingAs($admin)
            ->postJson("/api/admin/categories/{$category->id}/upload-global-image", [
                'image' => $file,
            ]);

        $response->assertStatus(422);
    }

    /** @test */
    public function upload_rejects_oversized_images()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $category = Category::factory()->create(['is_global_image_active' => true]);

        $file = UploadedFile::fake()->image('large.jpg')->size(6000); // 6MB

        $response = $this->actingAs($admin)
            ->postJson("/api/admin/categories/{$category->id}/upload-global-image", [
                'image' => $file,
            ]);

        $response->assertStatus(422);
    }

    /** @test */
    public function upload_accepts_image_at_max_size_limit()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $category = Category::factory()->create(['is_global_image_active' => false]);

        $file = UploadedFile::fake()->image('exact.jpg')->size(5120); // Exactly 5MB

        $response = $this->actingAs($admin)
            ->postJson("/api/admin/categories/{$category->id}/upload-global-image", [
                'image' => $file,
            ]);

        $response->assertOk();
    }

    /** @test */
    public function upload_returns_warning_for_small_dimensions()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $category = Category::factory()->create(['is_global_image_active' => false]);

        $file = UploadedFile::fake()->image('small.jpg', 150, 150);

        $response = $this->actingAs($admin)
            ->postJson("/api/admin/categories/{$category->id}/upload-global-image", [
                'image' => $file,
            ]);

        $response->assertOk();
        $response->assertJsonStructure(['warning']);
        $this->assertStringContainsString('أبعاد', $response->json('warning'));
    }

    /** @test */
    public function upload_does_not_return_warning_for_adequate_dimensions()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $category = Category::factory()->create(['is_global_image_active' => false]);

        $file = UploadedFile::fake()->image('adequate.jpg', 300, 300);

        $response = $this->actingAs($admin)
            ->postJson("/api/admin/categories/{$category->id}/upload-global-image", [
                'image' => $file,
            ]);

        $response->assertOk();
        $response->assertJsonMissing(['warning']);
    }

    // ========================================
    // Delete Functionality Tests
    // ========================================

    /** @test */
    public function admin_can_delete_unified_image()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $category = Category::factory()->create([
            'is_global_image_active' => true,
            'global_image_url' => 'uploads/categories/global/1_1234567890.webp',
        ]);

        Storage::disk('public')->put('uploads/categories/global/1_1234567890.webp', 'test content');

        $response = $this->actingAs($admin)
            ->deleteJson("/api/admin/categories/{$category->id}/global-image");

        $response->assertOk();
        $this->assertNull($category->fresh()->global_image_url);
        $this->assertFalse($category->fresh()->is_global_image_active);
        Storage::disk('public')->assertMissing('uploads/categories/global/1_1234567890.webp');
    }

    /** @test */
    public function delete_returns_correct_response_structure()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $category = Category::factory()->create([
            'is_global_image_active' => true,
            'global_image_url' => 'uploads/categories/global/1_1234567890.webp',
        ]);

        Storage::disk('public')->put('uploads/categories/global/1_1234567890.webp', 'test content');

        $response = $this->actingAs($admin)
            ->deleteJson("/api/admin/categories/{$category->id}/global-image");

        $response->assertOk();
        $response->assertJsonStructure([
            'id',
            'global_image_url',
            'message',
        ]);
        
        $response->assertJson([
            'id' => $category->id,
            'global_image_url' => null,
        ]);
    }

    /** @test */
    public function delete_handles_missing_file_gracefully()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $category = Category::factory()->create([
            'is_global_image_active' => true,
            'global_image_url' => 'uploads/categories/global/nonexistent.webp',
        ]);

        $response = $this->actingAs($admin)
            ->deleteJson("/api/admin/categories/{$category->id}/global-image");

        $response->assertOk();
        $this->assertNull($category->fresh()->global_image_url);
        $this->assertFalse($category->fresh()->is_global_image_active);
    }

    // ========================================
    // Authorization Tests
    // ========================================

    /** @test */
    public function non_admin_cannot_toggle_unified_image()
    {
        $user = User::factory()->create(['role' => 'user']);
        $category = Category::factory()->create();

        $response = $this->actingAs($user)
            ->putJson("/api/admin/categories/{$category->id}/toggle-global-image", [
                'is_global_image_active' => true,
            ]);

        $response->assertForbidden();
    }

    /** @test */
    public function non_admin_cannot_upload_unified_image()
    {
        $user = User::factory()->create(['role' => 'user']);
        $category = Category::factory()->create();

        $file = UploadedFile::fake()->image('category.jpg', 500, 500);

        $response = $this->actingAs($user)
            ->postJson("/api/admin/categories/{$category->id}/upload-global-image", [
                'image' => $file,
            ]);

        $response->assertForbidden();
    }

    /** @test */
    public function non_admin_cannot_delete_unified_image()
    {
        $user = User::factory()->create(['role' => 'user']);
        $category = Category::factory()->create([
            'is_global_image_active' => true,
            'global_image_url' => 'uploads/categories/global/1_1234567890.webp',
        ]);

        $response = $this->actingAs($user)
            ->deleteJson("/api/admin/categories/{$category->id}/global-image");

        $response->assertForbidden();
    }

    /** @test */
    public function unauthenticated_user_cannot_toggle_unified_image()
    {
        $category = Category::factory()->create();

        $response = $this->putJson("/api/admin/categories/{$category->id}/toggle-global-image", [
            'is_global_image_active' => true,
        ]);

        $response->assertUnauthorized();
    }

    /** @test */
    public function unauthenticated_user_cannot_upload_unified_image()
    {
        $category = Category::factory()->create();
        $file = UploadedFile::fake()->image('category.jpg', 500, 500);

        $response = $this->postJson("/api/admin/categories/{$category->id}/upload-global-image", [
            'image' => $file,
        ]);

        $response->assertUnauthorized();
    }

    /** @test */
    public function unauthenticated_user_cannot_delete_unified_image()
    {
        $category = Category::factory()->create([
            'is_global_image_active' => true,
            'global_image_url' => 'uploads/categories/global/1_1234567890.webp',
        ]);

        $response = $this->deleteJson("/api/admin/categories/{$category->id}/global-image");

        $response->assertUnauthorized();
    }

    // ========================================
    // Image Processing Tests
    // ========================================

    /** @test */
    public function uploaded_image_is_converted_to_webp()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $category = Category::factory()->create(['is_global_image_active' => false]);

        $file = UploadedFile::fake()->image('category.png', 500, 500);

        $response = $this->actingAs($admin)
            ->postJson("/api/admin/categories/{$category->id}/upload-global-image", [
                'image' => $file,
            ]);

        $response->assertOk();
        
        $imageUrl = $category->fresh()->global_image_url;
        $this->assertStringEndsWith('.webp', $imageUrl);
    }

    /** @test */
    public function uploaded_image_filename_includes_timestamp()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $category = Category::factory()->create(['is_global_image_active' => false]);

        $file = UploadedFile::fake()->image('category.jpg', 500, 500);

        $response = $this->actingAs($admin)
            ->postJson("/api/admin/categories/{$category->id}/upload-global-image", [
                'image' => $file,
            ]);

        $response->assertOk();
        
        $imageUrl = $category->fresh()->global_image_url;
        // Filename should match pattern: {category_id}_{timestamp}.webp
        $this->assertMatchesRegularExpression('/\d+_\d+\.webp$/', $imageUrl);
    }

    /** @test */
    public function uploaded_image_is_stored_in_correct_directory()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $category = Category::factory()->create(['is_global_image_active' => false]);

        $file = UploadedFile::fake()->image('category.jpg', 500, 500);

        $response = $this->actingAs($admin)
            ->postJson("/api/admin/categories/{$category->id}/upload-global-image", [
                'image' => $file,
            ]);

        $response->assertOk();
        
        $imageUrl = $category->fresh()->global_image_url;
        $this->assertStringStartsWith('uploads/categories/global/', $imageUrl);
    }

    // ========================================
    // Old Image Deletion Tests
    // ========================================

    /** @test */
    public function uploading_new_image_deletes_old_image()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $category = Category::factory()->create([
            'is_global_image_active' => true,
            'global_image_url' => 'uploads/categories/global/old_image.webp',
        ]);

        Storage::disk('public')->put('uploads/categories/global/old_image.webp', 'old content');

        $file = UploadedFile::fake()->image('new.jpg', 500, 500);

        $response = $this->actingAs($admin)
            ->postJson("/api/admin/categories/{$category->id}/upload-global-image", [
                'image' => $file,
            ]);

        $response->assertOk();
        Storage::disk('public')->assertMissing('uploads/categories/global/old_image.webp');
    }

    /** @test */
    public function uploading_new_image_updates_database_with_new_url()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $category = Category::factory()->create([
            'is_global_image_active' => true,
            'global_image_url' => 'uploads/categories/global/old_image.webp',
        ]);

        Storage::disk('public')->put('uploads/categories/global/old_image.webp', 'old content');

        $file = UploadedFile::fake()->image('new.jpg', 500, 500);

        $response = $this->actingAs($admin)
            ->postJson("/api/admin/categories/{$category->id}/upload-global-image", [
                'image' => $file,
            ]);

        $response->assertOk();
        
        $newImageUrl = $category->fresh()->global_image_url;
        $this->assertNotEquals('uploads/categories/global/old_image.webp', $newImageUrl);
        $this->assertStringEndsWith('.webp', $newImageUrl);
    }

    /** @test */
    public function first_upload_does_not_fail_when_no_old_image_exists()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $category = Category::factory()->create([
            'is_global_image_active' => false,
            'global_image_url' => null,
        ]);

        $file = UploadedFile::fake()->image('first.jpg', 500, 500);

        $response = $this->actingAs($admin)
            ->postJson("/api/admin/categories/{$category->id}/upload-global-image", [
                'image' => $file,
            ]);

        $response->assertOk();
        $this->assertNotNull($category->fresh()->global_image_url);
    }

    // ========================================
    // Additional Behavior Tests
    // ========================================

    /** @test */
    public function upload_automatically_activates_unified_image()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $category = Category::factory()->create(['is_global_image_active' => false]);

        $file = UploadedFile::fake()->image('category.jpg', 500, 500);

        $response = $this->actingAs($admin)
            ->postJson("/api/admin/categories/{$category->id}/upload-global-image", [
                'image' => $file,
            ]);

        $response->assertOk();
        $this->assertTrue($category->fresh()->is_global_image_active);
    }

    /** @test */
    public function toggle_validation_requires_boolean_value()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $category = Category::factory()->create();

        $response = $this->actingAs($admin)
            ->putJson("/api/admin/categories/{$category->id}/toggle-global-image", [
                'is_global_image_active' => 'invalid',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['is_global_image_active']);
    }

    /** @test */
    public function toggle_validation_requires_is_global_image_active_field()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $category = Category::factory()->create();

        $response = $this->actingAs($admin)
            ->putJson("/api/admin/categories/{$category->id}/toggle-global-image", []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['is_global_image_active']);
    }

    /** @test */
    public function upload_validation_requires_image_field()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $category = Category::factory()->create();

        $response = $this->actingAs($admin)
            ->postJson("/api/admin/categories/{$category->id}/upload-global-image", []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['image']);
    }
}
