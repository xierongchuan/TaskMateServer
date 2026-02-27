<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     *
     * Создаёт таблицу для хранения выходных/рабочих дней.
     * Позволяет настраивать календарь как глобально (dealership_id = null),
     * так и для конкретного автосалона.
     */
    public function up(): void
    {
        Schema::create('calendar_days', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('dealership_id')->unsigned()->nullable();
            $table->date('date');
            $table->string('type', 20)->default('holiday'); // 'holiday' | 'workday'
            $table->string('description', 255)->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->foreign('dealership_id')
                ->references('id')
                ->on('auto_dealerships')
                ->onDelete('cascade');

            // Уникальный ключ: один день может быть только один раз для каждого dealership
            $table->unique(['dealership_id', 'date']);
            $table->index(['date', 'type']);
            $table->index('dealership_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('calendar_days');
    }
};
