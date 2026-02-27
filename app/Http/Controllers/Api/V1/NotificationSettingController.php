<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\NotificationSetting;
use App\Enums\Role;
use App\Traits\HasDealershipAccess;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class NotificationSettingController extends Controller
{
    use HasDealershipAccess;

    /**
     * Get all notification settings for the user's dealership
     */
    public function index(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        // Get dealership ID - managers can manage their own dealership
        $dealershipId = (int) $request->input('dealership_id', $user->dealership_id);

        // Verify user has access to this dealership
        if ($accessError = $this->validateDealershipAccess($user, $dealershipId)) {
            return $accessError;
        }

        // Ensure default settings exist for all channels
        $this->ensureSettingsExist($dealershipId);

        $settings = NotificationSetting::where('dealership_id', $dealershipId)
            ->orderBy('channel_type')
            ->get()
            ->map(function ($setting) {
                return [
                    'id' => $setting->id,
                    'channel_type' => $setting->channel_type,
                    'channel_label' => NotificationSetting::getChannelLabel($setting->channel_type),
                    'is_enabled' => $setting->is_enabled,
                    'notification_time' => $setting->notification_time,
                    'notification_day' => $setting->notification_day,
                    'notification_offset' => $setting->notification_offset,
                    'recipient_roles' => $setting->recipient_roles ?? [],
                ];
            });

        return response()->json([
            'data' => $settings
        ]);
    }

    /**
     * Ensure notification settings exist for the dealership
     */
    private function ensureSettingsExist(int $dealershipId): void
    {
        $existingChannels = NotificationSetting::where('dealership_id', $dealershipId)
            ->pluck('channel_type')
            ->toArray();

        $allChannels = NotificationSetting::getAllChannelTypes();
        $missingChannels = array_diff($allChannels, $existingChannels);

        foreach ($missingChannels as $channel) {
            $defaultRoles = [Role::MANAGER->value, Role::OWNER->value];
            if ($channel === NotificationSetting::CHANNEL_TASK_ASSIGNED) {
                $defaultRoles[] = Role::EMPLOYEE->value;
            }

            $defaults = [
                'dealership_id' => $dealershipId,
                'channel_type' => $channel,
                'is_enabled' => true,
                'recipient_roles' => $defaultRoles,
            ];

            // Set specific defaults
            if ($channel === NotificationSetting::CHANNEL_DAILY_SUMMARY) {
                $defaults['notification_time'] = '20:00';
            } elseif ($channel === NotificationSetting::CHANNEL_WEEKLY_REPORT) {
                $defaults['notification_time'] = '09:00';
                $defaults['notification_day'] = 'monday';
            } elseif ($channel === NotificationSetting::CHANNEL_TASK_DEADLINE_30MIN) {
                $defaults['notification_offset'] = 30;
            } elseif ($channel === NotificationSetting::CHANNEL_TASK_HOUR_LATE) {
                $defaults['notification_offset'] = 60;
            }

            NotificationSetting::create($defaults);
        }
    }

    /**
     * Update a specific notification setting
     */
    public function update(Request $request, string $channelType): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        $validated = $request->validate([
            'dealership_id' => 'sometimes|exists:auto_dealerships,id',
            'is_enabled' => 'sometimes|boolean',
            'notification_time' => 'nullable|date_format:H:i',
            'notification_day' => 'nullable|string|in:monday,tuesday,wednesday,thursday,friday,saturday,sunday',
            'notification_offset' => 'nullable|integer|min:1|max:1440', // 1 minute to 24 hours
            'recipient_roles' => 'nullable|array',
            'recipient_roles.*' => 'string|in:employee,manager,owner,observer',
        ]);

        $dealershipId = $validated['dealership_id'] ?? $user->dealership_id;

        // Verify user has access to this dealership
        if ($accessError = $this->validateDealershipAccess($user, (int) $dealershipId)) {
            return $accessError;
        }

        $setting = NotificationSetting::where('dealership_id', $dealershipId)
            ->where('channel_type', $channelType)
            ->first();

        if (!$setting) {
            return response()->json([
                'message' => 'Notification setting not found'
            ], 404);
        }

        $setting->update([
            'is_enabled' => $validated['is_enabled'] ?? $setting->is_enabled,
            'notification_time' => $validated['notification_time'] ?? $setting->notification_time,
            'notification_day' => $validated['notification_day'] ?? $setting->notification_day,
            'notification_offset' => $validated['notification_offset'] ?? $setting->notification_offset,
            'recipient_roles' => $validated['recipient_roles'] ?? $setting->recipient_roles,
        ]);

        Log::info('Notification setting updated', [
            'user_id' => $user->id,
            'dealership_id' => $dealershipId,
            'channel_type' => $channelType,
            'is_enabled' => $setting->is_enabled
        ]);

        return response()->json([
            'data' => [
                'id' => $setting->id,
                'channel_type' => $setting->channel_type,
                'channel_label' => NotificationSetting::getChannelLabel($setting->channel_type),
                'is_enabled' => $setting->is_enabled,
                'notification_time' => $setting->notification_time,
                'notification_day' => $setting->notification_day,
                'notification_offset' => $setting->notification_offset,
                'recipient_roles' => $setting->recipient_roles ?? [],
            ]
        ]);
    }

    /**
     * Bulk update notification settings
     */
    public function bulkUpdate(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        $validated = $request->validate([
            'dealership_id' => 'sometimes|exists:auto_dealerships,id',
            'settings' => 'required|array',
            'settings.*.channel_type' => 'required|string',
            'settings.*.is_enabled' => 'sometimes|boolean',
            'settings.*.notification_time' => 'nullable|date_format:H:i',
            'settings.*.notification_day' => 'nullable|string',
        ]);

        $dealershipId = $validated['dealership_id'] ?? $user->dealership_id;

        // Verify user has access to this dealership
        if ($accessError = $this->validateDealershipAccess($user, (int) $dealershipId)) {
            return $accessError;
        }
        $updatedCount = 0;

        foreach ($validated['settings'] as $settingData) {
            $setting = NotificationSetting::where('dealership_id', $dealershipId)
                ->where('channel_type', $settingData['channel_type'])
                ->first();

            if ($setting) {
                $setting->update([
                    'is_enabled' => $settingData['is_enabled'] ?? $setting->is_enabled,
                    'notification_time' => $settingData['notification_time'] ?? $setting->notification_time,
                    'notification_day' => $settingData['notification_day'] ?? $setting->notification_day,
                ]);
                $updatedCount++;
            }
        }

        Log::info('Bulk notification settings updated', [
            'user_id' => $user->id,
            'dealership_id' => $dealershipId,
            'updated_count' => $updatedCount
        ]);

        return response()->json([
            'message' => 'Settings updated successfully',
            'updated_count' => $updatedCount
        ]);
    }

    /**
     * Reset all settings to defaults for a dealership
     */
    public function resetToDefaults(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        $dealershipId = (int) $request->input('dealership_id', $user->dealership_id);

        // Verify user has access to this dealership
        if ($accessError = $this->validateDealershipAccess($user, $dealershipId)) {
            return $accessError;
        }

        // Reset all settings to enabled
        NotificationSetting::where('dealership_id', $dealershipId)
            ->update([
                'is_enabled' => true,
            ]);

        // Reset times for scheduled notifications
        NotificationSetting::where('dealership_id', $dealershipId)
            ->where('channel_type', NotificationSetting::CHANNEL_DAILY_SUMMARY)
            ->update(['notification_time' => '20:00']);

        NotificationSetting::where('dealership_id', $dealershipId)
            ->where('channel_type', NotificationSetting::CHANNEL_WEEKLY_REPORT)
            ->update([
                'notification_time' => '09:00',
                'notification_day' => 'monday'
            ]);

        Log::info('Notification settings reset to defaults', [
            'user_id' => $user->id,
            'dealership_id' => $dealershipId
        ]);

        return response()->json([
            'message' => 'Settings reset to defaults successfully'
        ]);
    }
}
