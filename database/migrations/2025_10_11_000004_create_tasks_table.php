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
        Schema::create('tasks', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->text('comment')->nullable();
            $table->bigInteger('creator_id')->unsigned();
            $table->bigInteger('dealership_id')->unsigned()->nullable();
            $table->timestampTz('appear_date'); // Removed nullable
            $table->timestampTz('deadline');    // Removed nullable
            $table->string('recurrence', 50)->nullable(); // daily, weekly, monthly
            $table->string('task_type', 50)->default('individual'); // individual, group
            $table->string('response_type', 50)->default('acknowledge'); // acknowledge (OK), complete (Done/Postpone)
            $table->json('tags')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('postpone_count')->default(0);
            $table->timestampTz('archived_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->foreign('creator_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('dealership_id')->references('id')->on('auto_dealerships')->onDelete('cascade');

            $table->index('creator_id');
            $table->index('dealership_id');
            $table->index('task_type');
            $table->index('is_active');
            $table->index('appear_date');
            $table->index('deadline');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
