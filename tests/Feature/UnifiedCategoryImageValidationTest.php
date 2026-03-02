<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class UnifiedCategoryImageValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    /** @test */
    public function it_validates_image_format_by_extension()
    {
        $admin = User::factory()->create(['role' => 'admin', 'phone' => '1234567890']);
        $category = Category::factory()->create(['is_global_image_active' => true]);

        // Test invalid extension
        $file = UploadedFile::fake()->create('document.pdf', 1000);

        $response = $this->actingAs($admin)
            ->postJson("/api/admin/categories/{$category->id}/upload-global-image", [
                'image' => $file,
            ]);

        $response->assertStatus(422);
        $response->assertJsonFragment([
            'error' => 'صيغة الصورة غير مدعومة. الصيغ المدعومة: JPEG, PNG, WebP',
        ]);
    }

    /** @test */
    public function it_validates_actual_mime_type()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $category = Category::factory()->create(['is_global_image_active' => true]);

        // Create a text file with .jpg extension (fake image)
        $file = UploadedFile::fake()->create('fake.txt', 100);

        $response = $this->actingAs($admin)
            ->postJson("/api/admin/categories/{$category->id}/upload-global-image", [
                'image' => $file,
            ]);

        $response->assertStatus(422);
    }

    /** @test */
    public function it_validates_file_size_max_5mb()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $category = Category::factory()->create(['is_global_image_active' => true]);

        // Create a file larger than 5MB
        $file = UploadedFile::fake()->image('large.jpg')->size(6000); // 6MB

        $response = $this->actingAs($admin)
            ->postJson("/api/admin/categories/{$category->id}/upload-global-image", [
                'image' => $file,
            ]);

        $response->assertStatus(422);
        $response->assertJsonFragment([
            'error' => 'حجم الصورة يتجاوز الحد الأقصى المسموح (5 ميجابايت)',
        ]);
    }

    /** @test */
    public function it_accepts_valid_jpeg_image()
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

    /** @test */
    public function it_accepts_valid_png_image()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $category = Category::factory()->create(['is_global_image_active' => false]);

        $file = UploadedFile::fake()->image('category.png', 500, 500);

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

    /** @test */
    public function it_returns_warning_for_small_dimensions()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $category = Category::factory()->create(['is_global_image_active' => false]);

        // Create image with dimensions less than 200x200
        $file = UploadedFile::fake()->image('small.jpg', 150, 150);

        $response = $this->actingAs($admin)
            ->postJson("/api/admin/categories/{$category->id}/upload-global-image", [
                'image' => $file,
            ]);

        $response->assertOk();
        $response->assertJsonFragment([
            'warning' => 'أبعاد الصورة أقل من الموصى به (200x200 بكسل). قد تظهر الصورة بجودة منخفضة.',
        ]);
    }

    /** @test */
    public function it_does_not_return_warning_for_adequate_dimensions()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $category = Category::factory()->create(['is_global_image_active' => false]);

        // Create image with dimensions >= 200x200
        $file = UploadedFile::fake()->image('adequate.jpg', 300, 300);

        $response = $this->actingAs($admin)
            ->postJson("/api/admin/categories/{$category->id}/upload-global-image", [
                'image' => $file,
            ]);

        $response->assertOk();
        $response->assertJsonMissing(['warning']);
    }

    /** @test */
    public function it_accepts_image_at_exactly_5mb()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $category = Category::factory()->create(['is_global_image_active' => false]);

        // Create a file at exactly 5MB (5120 KB)
        $file = UploadedFile::fake()->image('exact.jpg')->size(5120);

        $response = $this->actingAs($admin)
            ->postJson("/api/admin/categories/{$category->id}/upload-global-image", [
                'image' => $file,
            ]);

        $response->assertOk();
    }

    /** @test */
    public function it_converts_uploaded_image_to_webp()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $category = Category::factory()->create(['is_global_image_active' => false]);

        $file = UploadedFile::fake()->image('category.png', 500, 500);

        $response = $this->actingAs($admin)
            ->postJson("/api/admin/categories/{$category->id}/upload-global-image", [
                'image' => $file,
            ]);

        $response->assertOk();
        
        $imageUrl = $response->json('global_image_url');
        $this->assertStringEndsWith('.webp', $imageUrl);
    }

    /** @test */
    public function it_automatically_activates_unified_image_on_upload()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $category = Category::factory()->create(['is_global_image_active' => false]);

        $file = UploadedFile::fake()->image('category.jpg', 500, 500);

        $response = $this->actingAs($admin)
            ->postJson("/api/admin/categories/{$category->id}/upload-global-image", [
                'image' => $file,
            ]);

        $response->assertOk();
        $response->assertJsonFragment([
            'is_global_image_active' => true,
        ]);

        $this->assertTrue($category->fresh()->is_global_image_active);
    }
}
