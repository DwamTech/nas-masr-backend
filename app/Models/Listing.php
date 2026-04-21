<?php

namespace App\Models;

use App\Support\Section;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;


class Listing extends Model
{
    use HasFactory;
    protected $fillable = [
        'category_id',
        'user_id',
        'title',
        'price',
        'currency',
        'description',
        'governorate_id',
        'city_id',
        'lat',
        'lng',
        'address',
        'main_image',
        'images',
        'status',
        'published_at',
        'plan_type',
        'publish_via',
        'contact_phone',
        'whatsapp_phone',
        'whatsapp_mode',
        'whatsapp_group_number_ids',
        'current_whatsapp_group_index',
        'make_id',
        'model_id',
        'rank',
        'country_code',
        'views',
        'whatsapp_clicks',
        'call_clicks',
        'admin_approved',
        'admin_comment',
        'expire_at',
        'isPayment',
        'main_section_id',
        'sub_section_id',

    ];

    protected $casts = [
        'images' => 'array',
        'published_at' => 'datetime',
        'expire_at' => 'datetime',
        'admin_approved' => 'boolean',
        'price' => 'decimal:2',
        'lat' => 'decimal:7',
        'lng' => 'decimal:7',
        'views' => 'int',
        'whatsapp_clicks' => 'int',
        'call_clicks' => 'int',
        'isPayment' => 'boolean',
        'whatsapp_group_number_ids' => 'array',
        'current_whatsapp_group_index' => 'int',

    ];

    /* ===================== العلاقات ===================== */

    public function reports(): HasMany
    {
        return $this->hasMany(ListingReport::class);
    }

    public function attributes(): HasMany
    {
        return $this->hasMany(ListingAttribute::class);
    }

    public function governorate()
    {
        return $this->belongsTo(Governorate::class);
    }

    public function city()
    {
        return $this->belongsTo(City::class);
    }

    public function make()
    {
        return $this->belongsTo(Make::class);
    }

    public function model()
    {
        return $this->belongsTo(CarModel::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
    // app/Models/Listing.php

    public function mainSection()
    {
        return $this->belongsTo(CategoryMainSection::class, 'main_section_id');
    }

    public function subSection()
    {
        return $this->belongsTo(CategorySubSection::class, 'sub_section_id');
    }



    public static function autoExpire(): int
    {
        return static::query()
            ->where('status', 'Valid')
            ->whereNotNull('expire_at')
            ->where('expire_at', '<=', now())
            ->update([
                'status' => 'Expired',
                'isPayment' => false,
            ]);
    }


    public function scopeActive($query)
    {
        return $query
            ->where('status', 'Valid')
            ->where(function ($q) {
                $q->whereNull('expire_at')
                    ->orWhere('expire_at', '>', now());
            });
    }


    public function scopeMostViewed(Builder $query)
    {
        return $query->orderBy('views', 'desc');
    }

    public function scopeByRank(Builder $query)
    {
        return $query->orderBy('rank', 'asc');
    }

    public function incrementViews(): void
    {
        $this->increment('views');
    }
    public static function typesByKeyForSection(Section $section): array
    {
        return collect($section->fields)->keyBy('field_name')->map(
            fn($f) => $f['type'] ?? 'string'
        )->all();
    }

    public static function attrColumnForType(string $type): string
    {
        return match ($type) {
            'int' => 'value_int',
            'decimal' => 'value_decimal',
            'bool' => 'value_bool',
            'date' => 'value_date',
            'json' => 'value_json',
            default => 'value_string',
        };
    }

    public function scopeForSection(Builder $q, Section $section): Builder
    {
        return $q->where('category_id', $section->id());
    }

    public function scopeWithBasics(Builder $q): Builder
    {
        return $q->with('attributes')->latest('id');
    }


    public function scopeKeyword(Builder $q, ?string $kw): Builder
    {
        $rawKw = trim((string)$kw);
        $kw = self::normalizeArabic($kw);
        if (!$kw) {
            return $q;
        }

        return $q->where(function ($qq) use ($kw, $rawKw) {
            $qq->where(function ($q) use ($kw) {
                $q->whereNotNull('title')
                  ->where('title', '!=', '')
                  ->whereRaw('REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(title,"أ","ا"),"إ","ا"),"آ","ا"),"ة","ه"),"ى","ي") like ?', ["%{$kw}%"]);
            })
                ->orWhereRaw('REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(description,"أ","ا"),"إ","ا"),"آ","ا"),"ة","ه"),"ى","ي") like ?', ["%{$kw}%"])
                ->orWhere('address', 'like', "%{$rawKw}%")
                ->orWhereHas('governorate', function ($q) use ($rawKw) {
                    $q->where('name', 'like', "%{$rawKw}%");
                })
                ->orWhereHas('city', function ($q) use ($rawKw) {
                    $q->where('name', 'like', "%{$rawKw}%");
                })
                ->orWhereHas('make', function ($q) use ($rawKw) {
                    $q->where('name', 'like', "%{$rawKw}%");
                })
                ->orWhereHas('model', function ($q) use ($rawKw) {
                    $q->where('name', 'like', "%{$rawKw}%");
                })
                ->orWhereHas('mainSection', function ($q) use ($rawKw) {
                    $q->where('name', 'like', "%{$rawKw}%");
                })
                ->orWhereHas('subSection', function ($q) use ($rawKw) {
                    $q->where('name', 'like', "%{$rawKw}%");
                })
                ->orWhereHas('attributes', function ($q) use ($rawKw) {
                    $q->where('value_string', 'like', "%{$rawKw}%");
                });
        });
    }


    public function scopeFilterGovernorate(Builder $q, ?string $govId, ?string $govName): Builder
    {
        if ($govId !== null && $govId !== '') {
            return $q->where('governorate_id', (int) $govId);
        }

        if ($govName !== null && trim($govName) !== '') {
            $norm = self::normalizeArabic($govName);

            $ids = DB::table('governorates')
                ->get()
                ->filter(function ($row) use ($norm) {
                    return self::normalizeArabic($row->name) === $norm;
                })
                ->pluck('id');

            if ($ids->isNotEmpty()) {
                $q->whereIn('governorate_id', $ids);
            }
        }

        return $q;
    }


    public function scopeFilterCity(Builder $q, ?string $cityId, ?string $cityName): Builder
    {
        if ($cityId !== null && $cityId !== '') {
            return $q->where('city_id', (int) $cityId);
        }

        if ($cityName !== null && trim($cityName) !== '') {
            $cityQuery = DB::table('cities')->where('name', 'like', '%' . trim($cityName) . '%');

            if (method_exists($q, '_govIds')) {
                $govIds = $q->_govIds();
                if ($govIds && $govIds->isNotEmpty()) {
                    $cityQuery->whereIn('governorate_id', $govIds);
                }
            }

            $cids = $cityQuery->pluck('id');
            if ($cids->isNotEmpty()) {
                $q->whereIn('city_id', $cids);
            }
        }

        return $q;
    }


    public function scopeStatusIs(Builder $q, ?string $status): Builder
    {
        if (!$status) {
            return $q;
        }

        return $q->where('status', $status);
    }


    public function scopePriceRange(Builder $q, $min, $max): Builder
    {
        if ($min !== null && $min !== '') {
            $q->where('price', '>=', (float) $min);
        }
        if ($max !== null && $max !== '') {
            $q->where('price', '<=', (float) $max);
        }
        return $q;
    }


    public function scopeAttrEq(Builder $q, array $pairs, array $typesByKey): Builder
    {
        foreach ($pairs as $key => $value) {
            if ($value === '' || $value === null) {
                continue;
            }

            $type = $typesByKey[$key] ?? 'string';
            $col = self::attrColumnForType($type);

            $normalizedInput = self::normalizeArabic((string) $value);

            $q->whereHas('attributes', function ($qa) use ($key, $col, $type, $value, $normalizedInput) {
                $qa->where('key', $key)->where(function ($qv) use ($col, $type, $value, $normalizedInput) {

                    if (in_array($type, ['int', 'decimal', 'bool', 'date'], true)) {
                        $qv->where($col, $value);
                    } else {
                        $normColumn = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE({$col},'أ','ا'),'إ','ا'),'آ','ا'),'ة','ه'),'ى','ي')";

                        $qv->where($col, (string) $value)
                            ->orWhereRaw("{$normColumn} = ?", [$normalizedInput]);
                    }
                });
            });
        }

        return $q;
    }


    public function scopeAttrIn(Builder $q, array $lists, array $typesByKey): Builder
    {
        foreach ($lists as $key => $vals) {
            $vals = array_values(array_filter((array) $vals, fn($v) => $v !== '' && $v !== null));
            if (!$vals) {
                continue;
            }

            $type = $typesByKey[$key] ?? 'string';
            $col = self::attrColumnForType($type);

            $q->whereHas('attributes', function ($qa) use ($key, $col, $type, $vals) {
                $qa->where('key', $key)->where(function ($qv) use ($col, $type, $vals) {

                    if ($type === 'bool') {
                        $mapped = array_map(
                            fn($v) => in_array((string) $v, ['1', 'true', 'on', 'yes', 'نعم'], true),
                            $vals
                        );
                        $qv->whereIn($col, $mapped);
                    } elseif (in_array($type, ['int', 'decimal'], true)) {
                        $qv->whereIn($col, array_map('floatval', $vals));
                    } else {
                        $qv->whereIn($col, array_map('strval', $vals));
                    }
                });
            });
        }

        return $q;
    }

    public function scopeAttrRange(Builder $q, array $mins, array $maxs, array $typesByKey): Builder
    {
        $keys = array_unique(array_merge(array_keys($mins), array_keys($maxs)));

        foreach ($keys as $key) {
            $min = $mins[$key] ?? null;
            $max = $maxs[$key] ?? null;

            if ($min === null && $max === null) {
                continue;
            }

            $type = $typesByKey[$key] ?? 'int';
            $col = self::attrColumnForType($type);

            $q->whereHas('attributes', function ($qa) use ($key, $col, $type, $min, $max) {
                $qa->where('key', $key);

                if ($min !== null && $min !== '') {
                    $qa->where($col, '>=', $type === 'date' ? (string) $min : (float) $min);
                }
                if ($max !== null && $max !== '') {
                    $qa->where($col, '<=', $type === 'date' ? (string) $max : (float) $max);
                }
            });
        }

        return $q;
    }

    public function scopeAttrLike(Builder $q, array $likes): Builder
    {
        foreach ($likes as $key => $like) {
            $like = trim((string) $like);
            if ($like === '') {
                continue;
            }

            $normalized = self::normalizeArabic($like);

            $q->whereHas('attributes', function ($qa) use ($key, $like, $normalized) {
                $normColumn = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(value_string,'أ','ا'),'إ','ا'),'آ','ا'),'ة','ه'),'ى','ي')";

                $qa->where('key', $key)
                    ->where(function ($qq) use ($like, $normalized, $normColumn) {
                        $qq->where('value_string', 'like', "%{$like}%")
                            ->orWhereRaw("{$normColumn} LIKE ?", ["%{$normalized}%"]);
                    });
            });
        }

        return $q;
    }


    public static function normalizeArabic(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        $text = trim($text);

        $text = str_replace(['أ', 'إ', 'آ'], 'ا', $text);
        $text = str_replace('ة', 'ه', $text);
        $text = str_replace('ى', 'ي', $text);

        $tashkeel = ['ً', 'ٌ', 'ٍ', 'َ', 'ُ', 'ِ', 'ّ', 'ْ'];
        $text = str_replace($tashkeel, '', $text);

        $text = preg_replace('/\s+/', ' ', $text);

        return $text;
    }
}
