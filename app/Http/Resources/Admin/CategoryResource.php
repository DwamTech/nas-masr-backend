<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CategoryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return   [
            'id'   => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'icon' => $this->icon,
            'icon_url' => $this->icon
                ? asset('storage/uploads/categories/' . $this->icon)
                : null,
            'is_active'=>$this->is_active,
            'show_featured_advertisers' => $this->show_featured_advertisers ?? true,
            'is_global_image_active' => $this->is_global_image_active ?? false,
            'global_image_url' => $this->global_image_url,
            'global_image_full_url' => $this->global_image_full_url,
            'sort_order'=>$this->sort_order,
        ];
    }
}
