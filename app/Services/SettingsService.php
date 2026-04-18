<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AutoDealership;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SettingsService
{
    const CACHE_TTL = 3600; // 1 hour

    const SETTING_NOT_FOUND = '___SETTING_NOT_FOUND___';

    /**
     * Get a setting value with caching.
     */
    public function get(string $key, ?int $dealershipId = null, mixed $default = null): mixed
    {
        $cacheKey = $this->getCacheKey($key, $dealershipId);

        // Check cache first
        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        // Query database with error handling
        try {
            $setting = Setting::where('key', $key)->where('dealership_id', $dealershipId)->first();
        } catch (\Exception $e) {
            // Логируем ошибку, но не раскрываем детали пользователю
            Log::warning('SettingsService: ошибка при получении настройки', [
                'key' => $key,
                'dealership_id' => $dealershipId,
                'error' => $e->getMessage(),
            ]);

            // Возвращаем default-значение при ошибке БД
            return $default;
        }

        if ($setting) {
            $value = $setting->getTypedValue();
            // Only cache actual values, not defaults
            Cache::put($cacheKey, $value, self::CACHE_TTL);

            return $value;
        }

        // Return default without caching (so we can retry later)
        return $default;
    }

    /**
     * Set a setting value.
     *
     * @throws \InvalidArgumentException When value is invalid for the type
     */
    public function set(
        string $key,
        mixed $value,
        ?int $dealershipId = null,
        string $type = 'string',
        ?string $description = null,
    ): Setting {
        // Validate value based on type
        $this->validateSettingValue($value, $type, $key);

        // Convert null to default value before creating/setting
        $processedValue = $this->processValueForStorage($value, $type);

        $setting = Setting::updateOrCreate(
            [
                'key' => $key,
                'dealership_id' => $dealershipId,
            ],
            [
                'type' => $type,
                'value' => $processedValue,
                'description' => $description,
            ],
        );

        // Set the typed value (this will handle any final conversions)
        $setting->setTypedValue($value);
        $setting->save();

        // Clear cache
        Cache::forget($this->getCacheKey($key, $dealershipId));

        return $setting;
    }

    /**
     * Process value for storage to avoid null constraint violations.
     */
    private function processValueForStorage(mixed $value, string $type): string|int|float
    {
        // If value is not null, convert it for initial storage
        if ($value !== null) {
            return match ($type) {
                'boolean' => $value ? '1' : '0',
                'integer' => (int) $value,
                'time' => (string) $value,
                'json' => json_encode($value),
                default => (string) $value,
            };
        }

        // Convert null to default values to avoid constraint violations
        return match ($type) {
            'integer' => 0,
            'boolean' => '0',
            'time' => '00:00',
            'json' => json_encode(null),
            default => '',
        };
    }

    /**
     * Validate setting value based on type.
     *
     * @throws \InvalidArgumentException
     */
    private function validateSettingValue(mixed $value, string $type, string $key): void
    {
        match ($type) {
            'time' => $this->validateTimeValue($value, $key),
            'integer' => $this->validateIntegerValue($value, $key),
            'boolean' => $this->validateBooleanValue($value, $key),
            'json' => $this->validateJsonValue($value, $key),
            default => null, // string type accepts any value
        };
    }

    /**
     * Validate time value.
     *
     * @throws \InvalidArgumentException
     */
    private function validateTimeValue(mixed $value, string $key): void
    {
        if ($value === null) {
            return; // null will be converted to default '00:00'
        }

        if (! is_string($value) && ! is_numeric($value)) {
            throw new \InvalidArgumentException("Time value for '{$key}' must be a string or numeric");
        }

        // Convert numeric hours to HH:MM format if needed
        if (is_numeric($value)) {
            $hours = (int) $value;
            if ($hours < 0 || $hours > 23) {
                throw new \InvalidArgumentException("Hour value for '{$key}' must be between 0 and 23");
            }

            return; // Allow numeric hours, will be converted in setTypedValue
        }

        // Validate HH:MM format
        if (! preg_match('/^([0-1][0-9]|2[0-3]):([0-5][0-9])$/', $value)) {
            throw new \InvalidArgumentException("Time value for '{$key}' must be in HH:MM format (24-hour)");
        }
    }

    /**
     * Validate integer value.
     *
     * @throws \InvalidArgumentException
     */
    private function validateIntegerValue(mixed $value, string $key): void
    {
        if ($value === null) {
            return; // null will be converted to default 0
        }

        if (! is_numeric($value)) {
            throw new \InvalidArgumentException("Integer value for '{$key}' must be numeric");
        }
    }

    /**
     * Validate boolean value.
     *
     * @throws \InvalidArgumentException
     */
    private function validateBooleanValue(mixed $value, string $key): void
    {
        if ($value === null) {
            return; // null will be converted to default false
        }

        if (! is_bool($value) && ! in_array($value, [0, 1, '0', '1', 'true', 'false'], true)) {
            throw new \InvalidArgumentException(
                "Boolean value for '{$key}' must be true, false, 0, 1, or equivalent strings",
            );
        }
    }

    /**
     * Validate JSON value.
     *
     * @throws \InvalidArgumentException
     */
    private function validateJsonValue(mixed $value, string $key): void
    {
        if ($value === null) {
            return; // null is valid JSON
        }

        if (! is_array($value) && ! is_object($value) && ! is_string($value)) {
            throw new \InvalidArgumentException("JSON value for '{$key}' must be an array, object, or JSON string");
        }

        if (is_string($value) && json_decode($value) === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new \InvalidArgumentException("Invalid JSON string for '{$key}': ".json_last_error_msg());
        }
    }

    /**
     * Get timezone for a dealership with fallback to global setting.
     *
     * Priority:
     * 1. Dealership's own timezone (from auto_dealerships.timezone)
     * 2. Global timezone setting (from settings table)
     * 3. Default '+05:00'
     *
     * @return string Timezone in UTC offset format (e.g., '+05:00')
     */
    public function getTimezone(?int $dealershipId = null): string
    {
        $default = '+05:00';

        // If dealership is specified, check its timezone first
        if ($dealershipId !== null) {
            $timezone = Cache::remember("dealership_timezone:{$dealershipId}", self::CACHE_TTL, function () use (
                $dealershipId,
            ) {
                $dealership = \App\Models\AutoDealership::find($dealershipId);

                return $dealership && ! empty($dealership->timezone) ? $dealership->timezone : null;
            });

            if ($timezone !== null) {
                return $timezone;
            }
        }

        // Fallback to global timezone setting
        return $this->get('global_timezone', null, $default);
    }

    /**
     * Get shift start time for a dealership.
     *
     * @param  int  $shiftNumber  1 or 2
     * @return string Time in HH:MM format
     */
    public function getShiftStartTime(?int $dealershipId = null, int $shiftNumber = 1): string
    {
        $key = $shiftNumber === 1 ? 'shift_1_start_time' : 'shift_2_start_time';
        $default = $shiftNumber === 1 ? '09:00' : '18:00';

        return $this->getSettingWithFallback($key, $dealershipId, $default);
    }

    /**
     * Get shift end time for a dealership.
     *
     * @param  int  $shiftNumber  1 or 2
     * @return string Time in HH:MM format
     */
    public function getShiftEndTime(?int $dealershipId = null, int $shiftNumber = 1): string
    {
        $key = $shiftNumber === 1 ? 'shift_1_end_time' : 'shift_2_end_time';
        $default = $shiftNumber === 1 ? '18:00' : '02:00';

        return $this->getSettingWithFallback($key, $dealershipId, $default);
    }

    /**
     * Get late tolerance in minutes.
     * Используется только для определения опоздания при открытии смены.
     */
    public function getLateTolerance(?int $dealershipId = null): int
    {
        return (int) $this->getSettingWithFallback('late_tolerance_minutes', $dealershipId, 15);
    }

    /**
     * Get shift reminder minutes.
     * Определяет, за сколько минут до начала смены она становится видимой
     * в списке доступных для открытия (а также окно «только что завершилась»).
     */
    public function getShiftReminderMinutes(?int $dealershipId = null): int
    {
        return (int) $this->getSettingWithFallback('shift_reminder_minutes', $dealershipId, 15);
    }

    /**
     * Get task archive days threshold.
     */
    public function getTaskArchiveDays(?int $dealershipId = null): int
    {
        return (int) $this->getSettingWithFallback('task_archive_days', $dealershipId, 30);
    }

    /**
     * Get weekly report day (0 = Sunday, 6 = Saturday).
     */
    public function getWeeklyReportDay(?int $dealershipId = null): int
    {
        return (int) $this->getSettingWithFallback('weekly_report_day', $dealershipId, 1); // Monday by default
    }

    /**
     * Get all settings for a user with dealership context.
     */
    public function getUserSettings(User $user): array
    {
        if (! $user->dealership_id) {
            // User not associated with dealership, return global settings only
            return $this->getGlobalSettings();
        }

        $dealershipId = $user->dealership_id;
        $globalSettings = $this->getGlobalSettings();
        $dealershipSettings = $this->getDealershipSettings($dealershipId);

        // Merge dealership settings with global fallbacks
        return array_merge($globalSettings, $dealershipSettings);
    }

    /**
     * Get global settings only.
     */
    private function getGlobalSettings(): array
    {
        $settings = Setting::whereNull('dealership_id')->get();

        $result = [];
        foreach ($settings as $setting) {
            $result[$setting->key] = $setting->getTypedValue();
        }

        return $result;
    }

    /**
     * Get dealership-specific settings.
     */
    private function getDealershipSettings(int $dealershipId): array
    {
        $settings = Setting::where('dealership_id', $dealershipId)->get();

        $result = [];
        foreach ($settings as $setting) {
            $result[$setting->key] = $setting->getTypedValue();
        }

        return $result;
    }

    /**
     * Get setting with smart fallback (dealership -> global).
     */
    public function getSettingWithFallback(string $key, ?int $dealershipId = null, mixed $default = null): mixed
    {
        // First try to get dealership-specific setting
        if ($dealershipId) {
            $dealershipValue = $this->get($key, $dealershipId, self::SETTING_NOT_FOUND);
            if ($dealershipValue !== self::SETTING_NOT_FOUND) {
                return $dealershipValue;
            }
        }

        // Fallback to global setting
        $globalValue = $this->get($key, null, self::SETTING_NOT_FOUND);
        if ($globalValue !== self::SETTING_NOT_FOUND) {
            return $globalValue;
        }

        return $default;
    }

    /**
     * Get multiple settings at once for efficiency.
     *
     * Batch-fetches uncached keys in a single query to avoid N+1 problem.
     */
    public function getMultipleSettings(array $keys, ?int $dealershipId = null): array
    {
        $result = [];
        $uncachedKeys = [];

        // Check cache first for each key
        foreach ($keys as $key) {
            if ($dealershipId) {
                $cacheKey = $this->getCacheKey($key, $dealershipId);
                if (Cache::has($cacheKey)) {
                    $result[$key] = Cache::get($cacheKey);

                    continue;
                }
            }

            $globalCacheKey = $this->getCacheKey($key, null);
            if (Cache::has($globalCacheKey)) {
                $result[$key] = Cache::get($globalCacheKey);

                continue;
            }

            $uncachedKeys[] = $key;
        }

        if (empty($uncachedKeys)) {
            return $result;
        }

        // Batch-fetch dealership-specific settings
        $dealershipSettings = [];
        if ($dealershipId) {
            $settings = Setting::where('dealership_id', $dealershipId)->whereIn('key', $uncachedKeys)->get();

            foreach ($settings as $setting) {
                $value = $setting->getTypedValue();
                Cache::put($this->getCacheKey($setting->key, $dealershipId), $value, self::CACHE_TTL);
                $dealershipSettings[$setting->key] = $value;
            }
        }

        // Find keys still missing after dealership lookup
        $stillMissing = array_diff($uncachedKeys, array_keys($dealershipSettings));

        // Batch-fetch global settings for remaining keys
        $globalSettings = [];
        if (! empty($stillMissing)) {
            $settings = Setting::whereNull('dealership_id')->whereIn('key', $stillMissing)->get();

            foreach ($settings as $setting) {
                $value = $setting->getTypedValue();
                Cache::put($this->getCacheKey($setting->key, null), $value, self::CACHE_TTL);
                $globalSettings[$setting->key] = $value;
            }
        }

        // Merge results: dealership settings take priority over global
        foreach ($uncachedKeys as $key) {
            $result[$key] = $dealershipSettings[$key] ?? ($globalSettings[$key] ?? null);
        }

        return $result;
    }

    /**
     * Set multiple settings at once for efficiency.
     *
     * @param  array  $settings  [key => value]
     * @param  array  $types  [key => type]
     * @param  array  $descriptions  [key => description]
     */
    public function setMultipleSettings(
        array $settings,
        ?int $dealershipId = null,
        array $types = [],
        array $descriptions = [],
    ): array {
        $results = [];

        foreach ($settings as $key => $value) {
            $type = $types[$key] ?? 'string';
            $description = $descriptions[$key] ?? null;

            $setting = $this->set($key, $value, $dealershipId, $type, $description);
            $results[$key] = $setting->getTypedValue();
        }

        return $results;
    }

    /**
     * Get cache key for a setting.
     */
    private function getCacheKey(string $key, ?int $dealershipId): string
    {
        return "setting:{$dealershipId}:{$key}";
    }

    /**
     * Clear all settings cache.
     */
    public function clearCache(): void
    {
        $settings = Setting::all();
        foreach ($settings as $setting) {
            Cache::forget($this->getCacheKey($setting->key, $setting->dealership_id));
        }
    }
}
