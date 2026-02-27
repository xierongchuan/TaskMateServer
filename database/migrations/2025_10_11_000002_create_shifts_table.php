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
        Schema::create('shifts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('user_id')->unsigned();
            $table->bigInteger('dealership_id')->unsigned();
            $table->timestampTz('shift_start');
            $table->timestampTz('shift_end')->nullable();
            $table->string('opening_photo_path', 500)->nullable();
            $table->string('closing_photo_path', 500)->nullable();
            $table->string('status', 50)->default('open'); // open, closed, late
            $table->integer('late_minutes')->default(0);
            $table->timestampTz('scheduled_start')->nullable();
            $table->timestampTz('scheduled_end')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('dealership_id')->references('id')->on('auto_dealerships')->onDelete('cascade');

            $table->index(['user_id', 'shift_start']);
            $table->index(['dealership_id', 'shift_start']);
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shifts');
    }
};
