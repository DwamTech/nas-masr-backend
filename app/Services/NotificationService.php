<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class NotificationService
{
    public const SOURCE_ADMIN = 'admin';
    public const SOURCE_ADVERTISER = 'advertiser';
    public const SOURCE_CLIENT = 'client';

    /**
     * Cooldown period in seconds for duplicate notifications
     */
    private const COOLDOWN_SECONDS = 120; // 2 minutes

    protected $firebase;

    public function __construct(FirebaseService $firebase)
    {
        $this->firebase = $firebase;
    }

    public function dispatch(
        int $userId,
        string $title,
        string $body,
        ?string $type = null,
        ?array $data = null,
        bool $bypassCooldown = false,
        ?string $sourceType = null
    ): array
    {
        $user = User::findOrFail($userId);
        $resolvedSourceType = $this->resolveSourceType($type, $bypassCooldown, $sourceType);
        $resolvedData = $this->mergeSourceTypeIntoData($data, $resolvedSourceType);

        // ✅ إشعارات الأدمن: لا قيود، لا cooldown، لا شيء - تنفيذ فوري
        if ($type === 'الاداره' || $bypassCooldown || $resolvedSourceType === self::SOURCE_ADMIN) {
            Log::info('🔵 Admin notification bypass activated', [
                'user_id' => $userId,
                'title' => $title,
                'bypass_flag' => $bypassCooldown
            ]);

            // Create internal notification
            $notification = Notification::create(
                $this->buildNotificationAttributes($user, $title, $body, $type, $resolvedSourceType, $resolvedData)
            );

            // Check if external notification should be sent
            $globalEnabled = Cache::remember('settings:enable_global_external_notif', now()->addHours(6), function () {
                $val = SystemSetting::where('key', 'enable_global_external_notif')->value('value');
                return (string) $val === '1';
            });

            $shouldSendExternal = $globalEnabled && (bool) $user->receive_external_notif;

            Log::info('🔍 External notification check', [
                'global_enabled' => $globalEnabled,
                'user_setting' => (bool) $user->receive_external_notif,
                'should_send' => $shouldSendExternal,
                'has_token' => !empty($user->fcm_token),
            ]);

            $externalSent = false;
            if ($shouldSendExternal) {
                $externalSent = $this->sendExternal($user, [
                    'title' => $title,
                    'body' => $body,
                    'type' => $type,
                    'source_type' => $resolvedSourceType,
                    'data' => $resolvedData,
                ]);
                
                Log::info('📤 External notification result', [
                    'sent' => $externalSent,
                    'user_id' => $userId,
                ]);
            }

            return [
                'notification' => $notification,
                'external_sent' => $externalSent,
                'skipped' => false,
                'admin_bypass' => true, // ✅ علامة توضح أن الإشعار من الأدمن
            ];
        }

        // ⏱️ الإشعارات العادية: تخضع للـ cooldown
        // Build cache key with type and listing_id for per-listing rate limiting
        $listingId = $resolvedData['listing_id'] ?? null;
        $cacheKeySuffix = $type ? ":{$type}" : '';
        $cacheKeySuffix .= $listingId ? ":{$listingId}" : '';
        $cacheKey = "notif:cooldown:{$user->id}{$cacheKeySuffix}";

        // Check cooldown
        $lastSent = Cache::get($cacheKey);
        $nowTs = now()->timestamp;
        
        if ($lastSent && ($nowTs - (int) $lastSent) < self::COOLDOWN_SECONDS) {
            // Within cooldown period - skip notification entirely
            return [
                'notification' => null,
                'external_sent' => false,
                'skipped' => true,
                'cooldown_remaining' => self::COOLDOWN_SECONDS - ($nowTs - (int) $lastSent),
            ];
        }

        // Cooldown passed - create internal notification
        $notification = Notification::create(
            $this->buildNotificationAttributes($user, $title, $body, $type, $resolvedSourceType, $resolvedData)
        );

        // Update cooldown timestamp
        Cache::put($cacheKey, $nowTs, now()->addSeconds(self::COOLDOWN_SECONDS));

        // Check if external notification should be sent
        $globalEnabled = Cache::remember('settings:enable_global_external_notif', now()->addHours(6), function () {
            $val = SystemSetting::where('key', 'enable_global_external_notif')->value('value');
            return (string) $val === '1';
        });

        $shouldSendExternal = $globalEnabled && (bool) $user->receive_external_notif;

        $externalSent = false;
        if ($shouldSendExternal) {
            $externalSent = $this->sendExternal($user, [
                'title' => $title,
                'body' => $body,
                'type' => $type,
                'source_type' => $resolvedSourceType,
                'data' => $resolvedData,
            ]);
        }

        return [
            'notification' => $notification,
            'external_sent' => $externalSent,
            'skipped' => false,
        ];
    }

    private function resolveSourceType(?string $type, bool $bypassCooldown, ?string $sourceType): ?string
    {
        if (!empty($sourceType)) {
            return $sourceType;
        }

        if ($type === 'الاداره' || $bypassCooldown) {
            return self::SOURCE_ADMIN;
        }

        return null;
    }

    private function mergeSourceTypeIntoData(?array $data, ?string $sourceType): ?array
    {
        if (empty($sourceType)) {
            return $data;
        }

        $resolvedData = $data ?? [];
        $resolvedData['source_type'] = $resolvedData['source_type'] ?? $sourceType;

        return $resolvedData;
    }

    private function buildNotificationAttributes(
        User $user,
        string $title,
        string $body,
        ?string $type,
        ?string $sourceType,
        ?array $data
    ): array {
        $attributes = [
            'user_id' => $user->id,
            'title' => $title,
            'body' => $body,
            'type' => $type,
            'data' => $data,
        ];

        if ($this->supportsSourceTypeColumn()) {
            $attributes['source_type'] = $sourceType;
        }

        return $attributes;
    }

    private function supportsSourceTypeColumn(): bool
    {
        return Cache::remember('schema:notifications:has_source_type', now()->addMinutes(30), function () {
            return Schema::hasColumn('notifications', 'source_type');
        });
    }

    protected function sendExternal(User $user, array $payload): bool
    {
        if (!$user->fcm_token) {
            Log::info('User has no FCM token', ['user_id' => $user->id]);
            return false;
        }

        return $this->firebase->sendToUser(
            $user->fcm_token,
            $payload['title'],
            $payload['body'],
            $payload['data'] ?? null
        );
    }
}
