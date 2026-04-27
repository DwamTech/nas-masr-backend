<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $savedLocations = $this->relationLoaded('savedLocations')
            ? $this->savedLocations
            : $this->savedLocations()->latest('id')->get();

        return [
            'id' => $this->id,
            // 'UserName' => $this->name,
            'phone' => $this->phone,
            'role'=>$this->role,
            'is_representative' => $this->is_representative ?? false,
            'show_ad_update_button' => (bool) ($this->show_ad_update_button ?? true),
            'referral_code' => $this->referral_code??null,
            'country_code' => $this->country_code??null,
            'created_at' => $this->created_at?->format('Y-m-d H:i'),
            'saved_locations' => $savedLocations->map(function ($location) {
                return [
                    'id' => $location->id,
                    'title' => $location->title,
                    'address' => $location->address,
                    'lat' => $location->lat,
                    'lng' => $location->lng,
                    'created_at' => $location->created_at?->toISOString(),
                    'updated_at' => $location->updated_at?->toISOString(),
                ];
            })->values(),
        ];
    }
}
