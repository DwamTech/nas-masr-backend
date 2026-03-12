<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\categoryRequest;
use App\Http\Resources\Admin\CategoryResource;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Make;
use App\Services\OptionRankService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class categoryController extends Controller
{
    public function index(Request $request)
    {

        $user = Request()->user();
        if ($user?->role == 'admin') {
            $q = Category::query()
                // ->where('is_active', true) 
                ->orderBy('sort_order', 'asc');
        } else {
            $q = Category::query()
                ->where('is_active', true)
                ->orderBy('sort_order', 'asc');

            if ($request->filled('active')) {
                $q->where('is_active', (bool) $request->boolean('active'));
            }
        }

        return CategoryResource::collection($q->get());
    }

    public function show(Category $category)
    {
        return new CategoryResource($category);
    }

    // POST /api/admin/categories
    public function store(categoryRequest $request)
    {
        $data = $request->validated();
        if ($request->hasFile('default_image')) {
            $path = $request->file('default_image')->store('categories', 'uploads');
            $data['default_image'] = basename($path);
        }

        if ($request->hasFile('icon')) {
            $path = $request->file('icon')->store('categories', 'uploads');
            $data['icon'] = basename($path);
        }

        $cat = Category::create($data);

        return response()->json([
            'message' => 'تم إنشاء القسم بنجاح',
            'data' => $cat,
        ], 201);
    }

    // PUT /api/admin/categories/{category}
    public function update(CategoryRequest $request, Category $category)
    {
        $data = $request->validated();

        if ($request->boolean('remove_default_image')) {
            $data['default_image'] = null;
        }

        if ($request->hasFile('default_image')) {
            $path = $request->file('default_image')->store('categories', 'uploads');
            $data['default_image'] = basename($path);
        }

        if ($request->hasFile('icon')) {
            $path = $request->file('icon')->store('categories', 'uploads');
            $data['icon'] = basename($path);
        }

        $category->update($data);

        return response()->json([
            'message' => 'تم تحديث القسم بنجاح',
            'data' => $category,
        ]);
    }


    public function destroy(Category $category)
    {
        $category->update(['is_active' => false]);

        return response()->json([
            'message' => 'تم تعطيل القسم',
        ]);
    }

    public function usageReport()
    {
        $categories = Category::withCount('listings')->get();

        return response()->json([
            'data' => $categories->map(function ($cat) {
                return [
                    'id' => $cat->id,
                    'name' => $cat->name,
                    'slug' => $cat->slug,
                    'icon_url' => $cat->icon_url,
                    'listings_count' => $cat->listings_count,
                ];
            }),
        ]);
    }

    /**
     * Update option ranks for a category field.
     * 
     * POST /api/admin/categories/{slug}/options/ranks
     * 
     * @param Request $request
     * @param string $slug
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateOptionRanks(Request $request, string $slug)
    {
        try {
            // Validate request
            $validated = $request->validate([
                'field' => 'required|string|max:255',
                'ranks' => 'required|array|min:1',
                'ranks.*.option' => 'required|string',
                'ranks.*.rank' => 'required|integer|min:1',
                'parentId' => 'nullable|string|max:255',
            ]);

            // Check if category exists
            $category = Category::where('slug', $slug)->first();
            if (!$category) {
                return response()->json([
                    'success' => false,
                    'message' => 'القسم غير موجود',
                ], 404);
            }

            $resolvedFieldName = $this->resolveRankFieldName(
                $validated['field'],
                $validated['parentId'] ?? null
            );

            // Use service to update ranks
            $service = new OptionRankService();
            $service->updateRanks($slug, $resolvedFieldName, $validated['ranks']);

            // Keep automotive make/model ranks synchronized across related categories.
            if ($this->shouldSyncAutomotiveRanks($resolvedFieldName)) {
                $this->syncAutomotiveRanks($slug, $resolvedFieldName, $validated['ranks'], $service);
            }

            return response()->json([
                'success' => true,
                'message' => 'تم تحديث الترتيب بنجاح',
                'data' => [
                    'updated_count' => count($validated['ranks']),
                    'field' => $resolvedFieldName,
                    'synced_automotive' => $this->shouldSyncAutomotiveRanks($resolvedFieldName),
                ],
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            // Re-throw validation exceptions to let Laravel handle them
            throw $e;

        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);

        } catch (\Exception $e) {
            Log::error('Failed to update option ranks', [
                'category' => $slug,
                'field' => $validated['field'] ?? null,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'حدث خطأ في حفظ الترتيب',
            ], 500);
        }
    }

    /**
     * Resolve the storage key for option ranks.
     * For model ranks with parent context, persist per-make key.
     *
     * @param string $fieldName
     * @param string|null $parentId
     * @return string
     */
    private function resolveRankFieldName(string $fieldName, ?string $parentId): string
    {
        $field = strtolower(trim($fieldName));
        $parent = trim((string) $parentId);

        // Canonical key for make/brand across categories.
        if (in_array($field, ['make', 'brand', 'car_make'], true)) {
            return 'brand';
        }

        // Canonical key for main sections in categories using main/sub sections.
        if ($field === 'main_section') {
            return 'MainSection';
        }

        // Canonical key for model across model/car_model naming variants.
        if (in_array($field, ['model', 'car_model'], true)) {
            if ($parent === '') {
                return 'model';
            }

            $makeId = $this->resolveMakeIdFromParent($parent);
            if ($makeId !== null) {
                // Canonical key for per-make model ranks
                return "model_make_id_{$makeId}";
            }

            // Fallback for compatibility when make cannot be resolved
            return "model_" . $this->normalizeRankToken($parent);
        }

        if ($parent === '') {
            return $field;
        }

        // Canonical per-main-section key for sub section ranks.
        if ($field === 'sub_section') {
            return "SubSection_{$parent}";
        }

        return $field;
    }

    /**
     * Resolve make id from rank parent context.
     * Accepts either numeric id or make name.
     *
     * @param string $parent
     * @return int|null
     */
    private function resolveMakeIdFromParent(string $parent): ?int
    {
        if ($parent === '') {
            return null;
        }

        if (ctype_digit($parent)) {
            $id = (int) $parent;
            return Make::whereKey($id)->exists() ? $id : null;
        }

        $normalized = $this->normalizeRankToken($parent);
        if ($normalized === '') {
            return null;
        }

        $make = Make::query()->get(['id', 'name'])->first(function ($m) use ($normalized) {
            return $this->normalizeRankToken((string) $m->name) === $normalized;
        });

        return $make?->id ? (int) $make->id : null;
    }

    /**
     * Normalize text token for rank keys matching.
     *
     * @param string $value
     * @return string
     */
    private function normalizeRankToken(string $value): string
    {
        $v = preg_replace('/\s+/u', ' ', trim($value));
        if (!is_string($v)) {
            return '';
        }
        return strtolower($v);
    }

    /**
     * Determine if rank key should be synchronized across automotive categories.
     *
     * @param string $resolvedFieldName
     * @return bool
     */
    private function shouldSyncAutomotiveRanks(string $resolvedFieldName): bool
    {
        return $resolvedFieldName === 'brand'
            || $resolvedFieldName === 'model'
            || $resolvedFieldName === 'car_model'
            || str_starts_with($resolvedFieldName, 'model_make_id_')
            || str_starts_with($resolvedFieldName, 'car_model_make_id_')
            || str_starts_with($resolvedFieldName, 'model_');
    }

    /**
     * Sync make/model ranks across cars, car rent, and spare-parts categories.
     *
     * @param string $sourceSlug
     * @param string $resolvedFieldName
     * @param array<int, array{option:string, rank:int}> $ranks
     * @param OptionRankService $service
     * @return void
     */
    private function syncAutomotiveRanks(string $sourceSlug, string $resolvedFieldName, array $ranks, OptionRankService $service): void
    {
        $automotiveSlugs = ['cars', 'cars_rent', 'spare-parts'];
        foreach ($automotiveSlugs as $targetSlug) {
            if ($targetSlug === $sourceSlug) {
                continue;
            }

            try {
                $service->updateRanks($targetSlug, $resolvedFieldName, $ranks);
            } catch (\Throwable $e) {
                Log::warning('automotive_rank_sync_failed', [
                    'source_slug' => $sourceSlug,
                    'target_slug' => $targetSlug,
                    'field' => $resolvedFieldName,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Toggle unified image status for a category.
     * 
     * PUT /api/admin/categories/{category}/toggle-global-image
     * 
     * @param Request $request
     * @param Category $category
     * @return \Illuminate\Http\JsonResponse
     */
    public function toggleGlobalImage(Request $request, Category $category)
    {
        try {
            // Validation
            $validated = $request->validate([
                'is_global_image_active' => 'required|boolean',
            ]);

            // Store old value for audit log
            $oldValue = $category->is_global_image_active;
            $newValue = $validated['is_global_image_active'];

            // Update category
            $category->update([
                'is_global_image_active' => $newValue,
            ]);

            // Create audit log
            AuditLog::log(
                'category_image_toggled',
                'Category',
                $category->id,
                ['is_global_image_active' => $oldValue],
                ['is_global_image_active' => $newValue]
            );

            // Log success
            Log::info('Unified image toggled successfully', [
                'category_id' => $category->id,
                'category_name' => $category->name,
                'old_value' => $oldValue,
                'new_value' => $newValue,
                'user_id' => auth()->id(),
            ]);

            return response()->json([
                'id' => $category->id,
                'is_global_image_active' => $category->is_global_image_active,
                'global_image_url' => $category->global_image_url,
                'message' => 'تم تحديث حالة الصورة الموحدة بنجاح',
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            // Re-throw validation exceptions to let Laravel handle them
            throw $e;

        } catch (\Exception $e) {
            // Log error with context
            Log::error('Failed to toggle unified image', [
                'category_id' => $category->id,
                'category_name' => $category->name,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => auth()->id(),
            ]);

            return response()->json([
                'error' => 'فشل تحديث حالة الصورة الموحدة. يرجى المحاولة مرة أخرى',
            ], 500);
        }
    }

    /**
     * Upload unified image for a category.
     * 
     * POST /api/admin/categories/{category}/upload-global-image
     * 
     * @param Request $request
     * @param Category $category
     * @return \Illuminate\Http\JsonResponse
     */
    public function uploadGlobalImage(Request $request, Category $category)
    {
        // Start database transaction for rollback capability
        DB::beginTransaction();

        try {
            // Set memory limit for image processing to prevent memory exhaustion
            $originalMemoryLimit = ini_get('memory_limit');
            ini_set('memory_limit', '256M');

            // Validation
            $validated = $request->validate([
                'image' => 'required|image|mimes:jpeg,png,jpg,webp|max:5120',
            ]);

            $uploadedFile = $validated['image'];
            
            // Security: Path Traversal Prevention
            // Sanitize the original filename to prevent path traversal attacks
            $originalFilename = basename($uploadedFile->getClientOriginalName());
            $originalFilename = preg_replace('/[^a-zA-Z0-9_\-\.]/', '', $originalFilename);
            
            // Verify the filename doesn't contain path traversal sequences
            if (strpos($originalFilename, '..') !== false || 
                strpos($originalFilename, '/') !== false || 
                strpos($originalFilename, '\\') !== false) {
                Log::warning('Path traversal attempt detected', [
                    'category_id' => $category->id,
                    'filename' => $uploadedFile->getClientOriginalName(),
                    'user_id' => auth()->id(),
                    'ip' => $request->ip(),
                ]);

                return response()->json([
                    'error' => 'اسم الملف غير صالح',
                ], 422);
            }
            
            // Validation 1: Verify file format (JPEG, PNG, WebP)
            $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp'];
            $extension = strtolower($uploadedFile->getClientOriginalExtension());
            if (!in_array($extension, $allowedExtensions)) {
                Log::warning('Invalid image format attempted', [
                    'category_id' => $category->id,
                    'extension' => $extension,
                    'user_id' => auth()->id(),
                ]);

                return response()->json([
                    'error' => 'صيغة الصورة غير مدعومة. الصيغ المدعومة: JPEG, PNG, WebP',
                ], 422);
            }

            // Validation 2: Verify actual MIME type (not just extension)
            $allowedMimeTypes = ['image/jpeg', 'image/png', 'image/webp'];
            $mimeType = $uploadedFile->getMimeType();
            if (!in_array($mimeType, $allowedMimeTypes)) {
                Log::warning('Invalid MIME type attempted', [
                    'category_id' => $category->id,
                    'mime_type' => $mimeType,
                    'user_id' => auth()->id(),
                ]);

                return response()->json([
                    'error' => 'نوع الملف غير صالح. يجب أن يكون الملف صورة من نوع JPEG, PNG, أو WebP',
                ], 422);
            }

            // Validation 3: Verify file size (max 5MB)
            $maxSize = 5 * 1024 * 1024; // 5MB in bytes
            if ($uploadedFile->getSize() > $maxSize) {
                Log::warning('Image size exceeds limit', [
                    'category_id' => $category->id,
                    'size' => $uploadedFile->getSize(),
                    'max_size' => $maxSize,
                    'user_id' => auth()->id(),
                ]);

                return response()->json([
                    'error' => 'حجم الصورة يتجاوز الحد الأقصى المسموح (5 ميجابايت)',
                ], 422);
            }

            // Validation 4: Verify actual image type using exif_imagetype
            $imageType = @exif_imagetype($uploadedFile->getRealPath());
            $allowedImageTypes = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP];
            if (!in_array($imageType, $allowedImageTypes)) {
                Log::warning('Invalid image type detected', [
                    'category_id' => $category->id,
                    'image_type' => $imageType,
                    'user_id' => auth()->id(),
                ]);

                return response()->json([
                    'error' => 'الملف ليس صورة صالحة',
                ], 422);
            }

            // Security: Image Bomb Protection
            // Check decompressed size estimate to prevent decompression bombs
            $imageInfo = @getimagesize($uploadedFile->getRealPath());
            if ($imageInfo) {
                $width = $imageInfo[0];
                $height = $imageInfo[1];
                
                // Estimate decompressed size (width * height * 4 bytes for RGBA)
                $estimatedDecompressedSize = $width * $height * 4;
                $maxDecompressedSize = 100 * 1024 * 1024; // 100MB
                
                if ($estimatedDecompressedSize > $maxDecompressedSize) {
                    Log::warning('Image bomb detected - decompressed size too large', [
                        'category_id' => $category->id,
                        'width' => $width,
                        'height' => $height,
                        'estimated_size' => $estimatedDecompressedSize,
                        'max_size' => $maxDecompressedSize,
                        'user_id' => auth()->id(),
                        'ip' => $request->ip(),
                    ]);

                    return response()->json([
                        'error' => 'أبعاد الصورة كبيرة جداً. الحد الأقصى المسموح: 5000x5000 بكسل',
                    ], 422);
                }
                
                // Additional check: reject images with extreme dimensions
                if ($width > 5000 || $height > 5000) {
                    Log::warning('Image dimensions exceed maximum', [
                        'category_id' => $category->id,
                        'width' => $width,
                        'height' => $height,
                        'user_id' => auth()->id(),
                    ]);

                    return response()->json([
                        'error' => 'أبعاد الصورة تتجاوز الحد الأقصى المسموح (5000x5000 بكسل)',
                    ], 422);
                }
            }

            // Create image resource from uploaded file
            switch ($imageType) {
                case IMAGETYPE_JPEG:
                    $sourceImage = @imagecreatefromjpeg($uploadedFile->getRealPath());
                    break;
                case IMAGETYPE_PNG:
                    $sourceImage = @imagecreatefrompng($uploadedFile->getRealPath());
                    break;
                case IMAGETYPE_WEBP:
                    $sourceImage = @imagecreatefromwebp($uploadedFile->getRealPath());
                    break;
                default:
                    Log::error('Unsupported image format', [
                        'category_id' => $category->id,
                        'image_type' => $imageType,
                        'user_id' => auth()->id(),
                    ]);

                    return response()->json([
                        'error' => 'صيغة الصورة غير مدعومة',
                    ], 422);
            }

            if (!$sourceImage) {
                Log::error('Failed to create image resource', [
                    'category_id' => $category->id,
                    'image_type' => $imageType,
                    'user_id' => auth()->id(),
                ]);

                return response()->json([
                    'error' => 'فشل معالجة الصورة. قد تكون الصورة تالفة',
                ], 422);
            }

            // Get original dimensions
            $originalWidth = imagesx($sourceImage);
            $originalHeight = imagesy($sourceImage);

            // Validation 5: Check image dimensions (warning if less than 200x200)
            $warning = null;
            if ($originalWidth < 200 || $originalHeight < 200) {
                $warning = 'أبعاد الصورة أقل من الموصى به (200x200 بكسل). قد تظهر الصورة بجودة منخفضة.';
                
                Log::info('Image dimensions below recommended', [
                    'category_id' => $category->id,
                    'width' => $originalWidth,
                    'height' => $originalHeight,
                    'user_id' => auth()->id(),
                ]);
            }

            // Calculate dimensions to fit 800x800 while maintaining aspect ratio
            $targetSize = 800;
            $scale = min($targetSize / $originalWidth, $targetSize / $originalHeight);
            $newWidth = (int)($originalWidth * $scale);
            $newHeight = (int)($originalHeight * $scale);

            // Create new image with target dimensions
            $resizedImage = imagecreatetruecolor($newWidth, $newHeight);
            
            // Preserve transparency for PNG
            imagealphablending($resizedImage, false);
            imagesavealpha($resizedImage, true);
            
            // Resize image
            imagecopyresampled(
                $resizedImage,
                $sourceImage,
                0, 0, 0, 0,
                $newWidth,
                $newHeight,
                $originalWidth,
                $originalHeight
            );

            // Generate filename with timestamp
            $timestamp = time();
            $filename = "{$category->id}_{$timestamp}.webp";
            $path = "uploads/categories/global/{$filename}";
            $fullPath = storage_path('app/public/' . $path);

            // Create directory if it doesn't exist (before path validation)
            $directory = dirname($fullPath);
            if (!file_exists($directory)) {
                if (!mkdir($directory, 0755, true)) {
                    Log::error('Failed to create directory', [
                        'category_id' => $category->id,
                        'directory' => $directory,
                        'user_id' => auth()->id(),
                    ]);

                    imagedestroy($sourceImage);
                    imagedestroy($resizedImage);

                    return response()->json([
                        'error' => 'فشل إنشاء مجلد التخزين',
                    ], 500);
                }
            }

            // Security: Verify path is within allowed directory (additional path traversal check)
            // Now that directory exists, realpath will work correctly
            $realPath = realpath(dirname($fullPath));
            $allowedBase = realpath(storage_path('app/public/uploads/categories/global'));
            
            if ($realPath === false || $allowedBase === false || strpos($realPath, $allowedBase) !== 0) {
                Log::error('Path traversal attempt detected in storage path', [
                    'category_id' => $category->id,
                    'path' => $path,
                    'full_path' => $fullPath,
                    'real_path' => $realPath,
                    'allowed_base' => $allowedBase,
                    'user_id' => auth()->id(),
                    'ip' => $request->ip(),
                ]);

                imagedestroy($sourceImage);
                imagedestroy($resizedImage);

                return response()->json([
                    'error' => 'مسار التخزين غير صالح',
                ], 500);
            }

            // Save as WebP
            if (!imagewebp($resizedImage, $fullPath, 85)) {
                Log::error('Failed to save WebP image', [
                    'category_id' => $category->id,
                    'path' => $fullPath,
                    'user_id' => auth()->id(),
                ]);

                imagedestroy($sourceImage);
                imagedestroy($resizedImage);

                return response()->json([
                    'error' => 'فشل حفظ الصورة',
                ], 500);
            }

            // Free memory
            imagedestroy($sourceImage);
            imagedestroy($resizedImage);

            // Store old image URL for rollback and audit
            $oldImageUrl = $category->global_image_url;

            // Update category with new image path and automatically activate the unified image
            $category->update([
                'global_image_url' => $path,
                'is_global_image_active' => true,
            ]);

            // Delete old image if exists (after successful database update)
            if ($oldImageUrl) {
                try {
                    Storage::disk('public')->delete($oldImageUrl);
                    
                    Log::info('Old unified image deleted', [
                        'category_id' => $category->id,
                        'old_image' => $oldImageUrl,
                        'user_id' => auth()->id(),
                    ]);
                } catch (\Exception $e) {
                    // Log but don't fail the request if old image deletion fails
                    Log::warning('Failed to delete old unified image', [
                        'category_id' => $category->id,
                        'old_image' => $oldImageUrl,
                        'error' => $e->getMessage(),
                        'user_id' => auth()->id(),
                    ]);
                }
            }

            // Create audit log
            AuditLog::log(
                'category_image_uploaded',
                'Category',
                $category->id,
                [
                    'global_image_url' => $oldImageUrl,
                    'is_global_image_active' => false,
                ],
                [
                    'global_image_url' => $path,
                    'is_global_image_active' => true,
                    'image_dimensions' => "{$newWidth}x{$newHeight}",
                ]
            );

            // Commit transaction
            DB::commit();

            // Restore original memory limit
            ini_set('memory_limit', $originalMemoryLimit);

            // Log success
            Log::info('Unified image uploaded successfully', [
                'category_id' => $category->id,
                'category_name' => $category->name,
                'image_path' => $path,
                'dimensions' => "{$newWidth}x{$newHeight}",
                'user_id' => auth()->id(),
            ]);

            $response = [
                'id' => $category->id,
                'global_image_url' => $category->global_image_url,
                'global_image_full_url' => $category->global_image_full_url,
                'is_global_image_active' => $category->is_global_image_active,
                'message' => 'تم رفع الصورة الموحدة بنجاح',
            ];

            // Add warning if dimensions are too small
            if ($warning) {
                $response['warning'] = $warning;
            }

            return response()->json($response);

        } catch (\Illuminate\Validation\ValidationException $e) {
            // Rollback transaction
            DB::rollBack();

            // Restore original memory limit
            if (isset($originalMemoryLimit)) {
                ini_set('memory_limit', $originalMemoryLimit);
            }

            // Re-throw validation exceptions to let Laravel handle them
            throw $e;

        } catch (\Exception $e) {
            // Rollback transaction
            DB::rollBack();

            // Restore original memory limit
            if (isset($originalMemoryLimit)) {
                ini_set('memory_limit', $originalMemoryLimit);
            }

            // Delete uploaded file if it exists
            if (isset($fullPath) && file_exists($fullPath)) {
                @unlink($fullPath);
            }

            // Log error with full context
            Log::error('Failed to upload unified image', [
                'category_id' => $category->id,
                'category_name' => $category->name,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => auth()->id(),
            ]);

            return response()->json([
                'error' => 'فشل رفع الصورة. يرجى المحاولة مرة أخرى',
            ], 500);
        }
    }

    /**
     * Delete unified image for a category.
     * 
     * DELETE /api/admin/categories/{category}/global-image
     * 
     * @param Category $category
     * @return \Illuminate\Http\JsonResponse
     */
    public function deleteGlobalImage(Category $category)
    {
        // Start database transaction for rollback capability
        DB::beginTransaction();

        try {
            // Store old values for audit log
            $oldImageUrl = $category->global_image_url;
            $oldActiveStatus = $category->is_global_image_active;

            // Delete image file if exists
            if ($oldImageUrl) {
                try {
                    Storage::disk('public')->delete($oldImageUrl);
                    
                    Log::info('Unified image file deleted', [
                        'category_id' => $category->id,
                        'image_path' => $oldImageUrl,
                        'user_id' => auth()->id(),
                    ]);
                } catch (\Exception $e) {
                    // Log warning but continue with database update
                    Log::warning('Failed to delete unified image file', [
                        'category_id' => $category->id,
                        'image_path' => $oldImageUrl,
                        'error' => $e->getMessage(),
                        'user_id' => auth()->id(),
                    ]);
                }
            }

            // Update category
            $category->update([
                'global_image_url' => null,
                'is_global_image_active' => false,
            ]);

            // Create audit log
            AuditLog::log(
                'category_image_deleted',
                'Category',
                $category->id,
                [
                    'global_image_url' => $oldImageUrl,
                    'is_global_image_active' => $oldActiveStatus,
                ],
                [
                    'global_image_url' => null,
                    'is_global_image_active' => false,
                ]
            );

            // Commit transaction
            DB::commit();

            // Log success
            Log::info('Unified image deleted successfully', [
                'category_id' => $category->id,
                'category_name' => $category->name,
                'old_image' => $oldImageUrl,
                'user_id' => auth()->id(),
            ]);

            return response()->json([
                'id' => $category->id,
                'global_image_url' => null,
                'message' => 'تم حذف الصورة الموحدة بنجاح',
            ]);

        } catch (\Exception $e) {
            // Rollback transaction
            DB::rollBack();

            // Log error with context
            Log::error('Failed to delete unified image', [
                'category_id' => $category->id,
                'category_name' => $category->name,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => auth()->id(),
            ]);

            return response()->json([
                'error' => 'فشل حذف الصورة. يرجى المحاولة مرة أخرى',
            ], 500);
        }
    }
}
