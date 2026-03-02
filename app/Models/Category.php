<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    use HasFactory;

    protected $fillable = [
        'slug',
        'name',
        'title',
        'icon',
        'default_image',
        'sort_order',
        'is_active',
        'is_global_image_active',
        'global_image_url',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_global_image_active' => 'boolean',
    ];
    protected $appends = ['icon_url', 'default_image_url', 'global_image_full_url'];

    public function getIconUrlAttribute(): ?string
    {
        if (!$this->icon) {
            return null;
        }

        return asset('storage/uploads/categories/' . $this->icon);
    }

    public function getDefaultImageUrlAttribute(): ?string
    {
        if (!$this->default_image) {
            return null;
        }

        return asset('storage/uploads/categories/' . $this->default_image);
    }

    public function getGlobalImageFullUrlAttribute(): ?string
    {
        if (!$this->global_image_url) {
            return null;
        }
        
        return asset('storage/' . $this->global_image_url);
    }
    public function listings()
    {
        return $this->hasMany(\App\Models\Listing::class);
    }

    public function planPrice()
    {
        return $this->hasOne(\App\Models\CategoryPlanPrice::class);
    }
}
