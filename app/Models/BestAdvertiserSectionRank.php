<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BestAdvertiserSectionRank extends Model
{
    protected $fillable = [
        'best_advertiser_id',
        'category_id',
        'rank',
    ];

    protected $casts = [
        'best_advertiser_id' => 'integer',
        'category_id' => 'integer',
        'rank' => 'integer',
    ];

    public function bestAdvertiser()
    {
        return $this->belongsTo(BestAdvertiser::class);
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }
}
