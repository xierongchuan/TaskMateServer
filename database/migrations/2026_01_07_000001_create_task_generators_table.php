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
        Schema::create('task_generators', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->text('comment')->nullable();
            $table->bigInteger('creator_id')->unsigned();
            $table->bigInteger('dealership_id')->unsigned()->nullable();

            // Recurrence settings
            $table->string('recurrence', 50); // daily, weekly, monthly
            $table->time('recurrence_time'); // время появления задачи
            $table->time('deadline_time'); // время дедлайна (в тот же день)
            $table->integer('recurrence_day_of_week')->nullable(); // 1-7 для weekly
            $table->integer('recurrence_day_of_month')->nullable(); // 1-31 или -1, -2 для monthly

            // Period
            $table->timestampTz('start_date'); // дата начала генерации
            $table->timestampTz('end_date')->nullable(); // дата окончания (null = бессрочно)
            $table->timestampTz('last_generated_at')->nullable(); // когда последний раз создана задача

            // Task configuration
            $table->string('task_type', 50)->default('individual'); // individual, group
            $table->string('response_type', 50)->default('acknowledge'); // acknowledge, complete
            $table->string('priority', 20)->default('medium'); // low, medium, high
            $table->json('tags')->nullable();
            $table->json('notification_settings')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            // Foreign keys
            $table->foreign('creator_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('dealership_id')->references('id')->on('auto_dealerships')->onDelete('cascade');

            // Indexes
            $table->index('creator_id');
            $table->index('dealership_id');
            $table->index('is_active');
            $table->index('recurrence');
            $table->index('last_generated_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('task_generators');
    }
};
