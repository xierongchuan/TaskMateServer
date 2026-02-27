<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('task_notifications', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('task_id')->unsigned();
            $table->bigInteger('user_id')->unsigned();
            $table->string('notification_type', 50); // 'upcoming_deadline', 'overdue', 'hour_overdue', 'unresponded_2h', 'unresponded_6h', 'unresponded_24h'
            $table->timestampTz('sent_at')->useCurrent();
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('task_id')->references('id')->on('tasks')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');

            // Composite unique index to prevent duplicate notifications of same type
            $table->unique(['task_id', 'user_id', 'notification_type'], 'unique_task_user_notification');

            $table->index('task_id');
            $table->index('user_id');
            $table->index('notification_type');
            $table->index('sent_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('task_notifications');
    }
};
