<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;


class BestAdvertiser extends Model
{

    protected $table = 'best_advertiser';
    protected $fillable = [
        'user_id',
        'category_ids',
        'max_listings',
        'rank',
        'is_active',
    ];

    protected $casts = [
        'category_ids' => 'array',
        'is_active' => 'boolean',
        'max_listings' => 'integer',
        'rank' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function sectionRanks()
    {
        return $this->hasMany(BestAdvertiserSectionRank::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->whereHas('user', function ($q) {
            $q->where('status', 'active');
        });
    }
}
