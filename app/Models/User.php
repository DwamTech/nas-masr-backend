<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use App\Models\Listing;
use App\Models\UserConversation;
use Illuminate\Support\Facades\Storage;


class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'email_verified_at',
        'referral_code',
        'lat',
        'lng',
        'status',
        'receive_external_notif',
        'address',
        'country_code',
        'otp',
        'otp_verified_at',
        'role',
        'allowed_dashboard_pages',
        'profile_image',
        'whatsapp_numbers_group',
        'is_representative',
        'fcm_token',
        'guest_uuid',
    ];


    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'otp_verified' => 'boolean',
            'receive_external_notif' => 'boolean',
            'is_representative' => 'boolean',
            'allowed_dashboard_pages' => 'array',
            'whatsapp_numbers_group' => 'array',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function listings(): HasMany
    {
        return $this->hasMany(Listing::class);
    }

    public function savedLocations(): HasMany
    {
        return $this->hasMany(UserSavedLocation::class)->latest('id');
    }

    public function reports(): HasMany
    {
        return $this->hasMany(ListingReport::class);
    }

    /**
     * Get all messages sent by this user.
     */
    public function sentMessages(): MorphMany
    {
        return $this->morphMany(UserConversation::class, 'sender');
    }

    /**
     * Get all messages received by this user.
     */
    public function receivedMessages(): MorphMany
    {
        return $this->morphMany(UserConversation::class, 'receiver');
    }

    /**
     * Get all conversations this user is part of (sent or received).
     */
    public function conversations()
    {
        return UserConversation::forUser($this->id, self::class)
            ->select('conversation_id')
            ->distinct();
    }

    /*
    |--------------------------------------------------------------------------
    | Chat Helper Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Get unread messages count for this user.
     */
    public function unreadMessagesCount(): int
    {
        return $this->receivedMessages()->unread()->count();
    }

    /**
     * Get unread messages for this user.
     */
    public function unreadMessages()
    {
        return $this->receivedMessages()->unread()->orderBy('created_at', 'desc');
    }

    /**
     * Check if user is admin.
     */
    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    /**
     * Check if user is an employee with dashboard access.
     */
    public function isEmployee(): bool
    {
        return $this->role === 'employee';
    }

    /**
     * Dashboard staff roles that should only be managed by admins.
     *
     * @return array<int, string>
     */
    public static function privilegedDashboardRoles(): array
    {
        return ['admin', 'reviewer', 'employee'];
    }

    /**
     * Check if the user belongs to a privileged dashboard role.
     */
    public function isPrivilegedDashboardRole(): bool
    {
        return in_array((string) $this->role, self::privilegedDashboardRoles(), true);
    }

    /**
     * Check if the user may access dashboard routes.
     */
    public function canAccessDashboard(): bool
    {
        return $this->isAdmin() || $this->isEmployee();
    }

    /**
     * Returns normalized dashboard page keys for the user.
     *
     * @return array<int, string>
     */
    public function dashboardPageKeys(): array
    {
        $raw = $this->allowed_dashboard_pages;

        if (!is_array($raw)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn ($value) => is_string($value) ? trim($value) : null,
            $raw
        ))));
    }

    /**
     * Check if the user has access to a dashboard page key.
     */
    public function hasDashboardPage(string $pageKey): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        return in_array($pageKey, $this->dashboardPageKeys(), true);
    }

    /**
     * Full URL for profile image if available.
     */
    public function getProfileImageUrlAttribute(): ?string
    {
        if (!$this->profile_image) {
            return null;
        }

        if (str_starts_with($this->profile_image, 'http://') || str_starts_with($this->profile_image, 'https://')) {
            return $this->profile_image;
        }

        return Storage::disk('public')->url($this->profile_image);
    }

    /**
     * Check if user is a representative (delegate).
     */
    public function isRepresentative(): bool
    {
        return $this->is_representative === true;
    }

    /**
     * Check if user is an advertiser.
     */
    public function isAdvertiser(): bool
    {
        return $this->role === 'advertiser';
    }
}
