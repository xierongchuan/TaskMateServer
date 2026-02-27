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
        Schema::create('shift_replacements', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('shift_id')->unsigned();
            $table->bigInteger('replacing_user_id')->unsigned();
            $table->bigInteger('replaced_user_id')->unsigned();
            $table->text('reason')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->foreign('shift_id')->references('id')->on('shifts')->onDelete('cascade');
            $table->foreign('replacing_user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('replaced_user_id')->references('id')->on('users')->onDelete('cascade');

            $table->index('shift_id');
            $table->index('replacing_user_id');
            $table->index('replaced_user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shift_replacements');
    }
};
