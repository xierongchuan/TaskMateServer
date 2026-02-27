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
        Schema::create('task_generator_assignments', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('generator_id')->unsigned();
            $table->bigInteger('user_id')->unsigned();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            // Foreign keys
            $table->foreign('generator_id')->references('id')->on('task_generators')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');

            // Unique constraint: user can only be assigned once per generator
            $table->unique(['generator_id', 'user_id']);

            // Indexes
            $table->index('generator_id');
            $table->index('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('task_generator_assignments');
    }
};
