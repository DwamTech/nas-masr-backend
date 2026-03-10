<?php

namespace App\Http\Resources;

use App\Support\Section;
use Illuminate\Http\Resources\Json\JsonResource;

class ListingResource extends JsonResource
{
    public function toArray($request): array
    {
        $categorySlug = $this->category_id ? Section::fromId($this->category_id)?->slug : null;

        if ($categorySlug === 'jobs' || $categorySlug === 'doctors' || $categorySlug === 'teachers') {
            $key = $categorySlug . '_default_image';
            $defPath = \Illuminate\Support\Facades\Cache::remember(
                "settings:{$key}",
                now()->addHours(6),
                fn() => \App\Models\SystemSetting::where('key', $key)->value('value')
            );
            $mainUrl = $defPath ? asset('storage/' . $defPath) : null;
        } else {
            $mainUrl = $this->main_image ? asset('storage/' . $this->main_image) : null;
        }

        $gallery = [];
        $imgs = $this->images;
        if (is_string($imgs)) {
            $imgs = json_decode($imgs, true);
        }
        if (is_array($imgs)) {
            foreach ($imgs as $p) {
                $gallery[] = asset('storage/' . $p);
            }
        }

        $attrs = [];
        if ($this->relationLoaded('attributes')) {
            foreach ($this->attributes as $row) {
                $attrs[$row->key] = $this->castEavValue($row);
            }
        }

        $governorateName = $this->relationLoaded('governorate') && $this->governorate
            ? $this->governorate->name
            : null;

        $cityName = $this->relationLoaded('city') && $this->city
            ? $this->city->name
            : null;

        $sec = $this->category_id ? Section::fromId($this->category_id) : null;
        $categorySlug = $sec?->slug;
        $categoryName = $sec?->name;

        $supportsMakeModel = $sec?->supportsMakeModel() ?? false;
        $supportsSections = $sec?->supportsSections() ?? false;

        $makeName = ($supportsMakeModel && $this->relationLoaded('make') && $this->make) ? $this->make->name : null;
        $modelName = ($supportsMakeModel && $this->relationLoaded('model') && $this->model) ? $this->model->name : null;

        $mainSectionName = ($supportsSections && $this->relationLoaded('mainSection') && $this->mainSection)
            ? $this->mainSection->name
            : null;

        $subSectionName = ($supportsSections && $this->relationLoaded('subSection') && $this->subSection)
            ? $this->subSection->name
            : null;

        $viewer = $request->user();
        $canViewClickMetrics = $viewer
            && (($viewer->role ?? null) === 'admin' || (int) $viewer->id === (int) $this->user_id);

        return [
            'id' => $this->id,

            'category_id' => $this->category_id,
            'category' => $categorySlug,
            'category_name' => $categoryName,

            'title' => $this->title,
            'price' => $this->price,
            'currency' => $this->currency,
            'description' => $this->description,

            'governorate' => $governorateName,
            'city' => $cityName,
            'lat' => $this->lat,
            'lng' => $this->lng,
            'address' => $this->address,

            'status' => $this->status,
            'published_at' => $this->published_at,
            'plan_type' => $this->plan_type,

            'contact_phone' => $this->contact_phone,
            'whatsapp_phone' => $this->whatsapp_phone,

            'make_id' => $this->when($supportsMakeModel, $this->make_id),
            'make' => $this->when($supportsMakeModel, $makeName),
            'model_id' => $this->when($supportsMakeModel, $this->model_id),
            'model' => $this->when($supportsMakeModel, $modelName),

            'main_section_id' => $this->when($supportsSections, $this->main_section_id),
            'main_section' => $this->when($supportsSections, $mainSectionName),
            'sub_section_id' => $this->when($supportsSections, $this->sub_section_id),
            'sub_section' => $this->when($supportsSections, $subSectionName),

            'main_image' => $this->main_image,
            'main_image_url' => $mainUrl,
            'images' => $imgs,
            'images_urls' => $gallery,

            'attributes' => $attrs,
            'views' => $this->views,
            'whatsapp_clicks' => $this->when($canViewClickMetrics, (int) ($this->whatsapp_clicks ?? 0)),
            'call_clicks' => $this->when($canViewClickMetrics, (int) ($this->call_clicks ?? 0)),
            'rank' => $this->rank,
            'country_code' => $this->country_code,

            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'expire_at' => $this->expire_at ?? "قيد الانتظار",
            'isPayment' => $this->isPayment,
            'publish_via'=>$this->publish_via,
            'admin_comment'=>$this->admin_comment??"لا توجد اي تعليقات من قبل الادمن ",
            'user' => [
                'id' => (int) $this->user_id,
                'name' => ($this->relationLoaded('user') && $this->user) ? $this->user->name : null,
                'phone' => ($this->relationLoaded('user') && $this->user) ? $this->user->phone : null,
            ],
        ];
    }

    protected function castEavValue($attr)
    {
        return $attr->value_int
            ?? $attr->value_decimal
            ?? $attr->value_bool
            ?? $attr->value_string
            ?? $this->decodeJsonSafe($attr->value_json)
            ?? $attr->value_date
            ?? null;
    }

    protected function decodeJsonSafe($json)
    {
        if (is_null($json))
            return null;
        if (is_array($json))
            return $json;

        $x = json_decode($json, true);
        return json_last_error() === JSON_ERROR_NONE ? $x : $json;
    }
}
