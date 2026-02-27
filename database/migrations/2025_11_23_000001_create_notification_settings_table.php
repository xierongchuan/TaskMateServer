<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('notification_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dealership_id')
                ->constrained('auto_dealerships')
                ->onDelete('cascade');
            $table->string('channel_type', 50); // e.g., 'task_assigned', 'task_overdue'
            $table->boolean('is_enabled')->default(true);
            $table->time('notification_time')->nullable(); // For scheduled notifications
            $table->string('notification_day')->nullable(); // For weekly reports (e.g., 'monday')
            $table->timestamps();

            // Ensure one setting per channel per dealership
            $table->unique(['dealership_id', 'channel_type']);
        });

        // Create default settings for existing dealerships
        // Create default settings for existing dealerships
        $dealerships = DB::table('auto_dealerships')->pluck('id');
        $channels = [
            'task_assigned',
            'task_deadline_30min',
            'task_overdue',
            'task_hour_late',
            'shift_late',
            'task_postponed',
            'shift_replacement',
            'daily_summary',
            'weekly_report',
        ];

        $now = now();
        $data = [];

        foreach ($dealerships as $dealershipId) {
            foreach ($channels as $channel) {
                $notificationTime = null;
                $notificationDay = null;

                if ($channel === 'daily_summary') {
                    $notificationTime = '20:00:00';
                } elseif ($channel === 'weekly_report') {
                    $notificationTime = '09:00:00';
                    $notificationDay = 'monday';
                }

                $data[] = [
                    'dealership_id' => $dealershipId,
                    'channel_type' => $channel,
                    'is_enabled' => true,
                    'notification_time' => $notificationTime,
                    'notification_day' => $notificationDay,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        if (!empty($data)) {
            DB::table('notification_settings')->insert($data);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notification_settings');
    }
};
