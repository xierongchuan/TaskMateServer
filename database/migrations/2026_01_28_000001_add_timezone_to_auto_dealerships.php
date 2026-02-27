<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Добавляет поле timezone к автосалонам.
     *
     * Timezone используется для определения границ календарных дней
     * при проверке выходных/праздников. Каждый автосалон имеет своё
     * физическое местоположение и работает в определённом часовом поясе.
     */
    public function up(): void
    {
        Schema::table('auto_dealerships', function (Blueprint $table) {
            $table->string('timezone', 50)
                ->default('+05:00')
                ->after('phone')
                ->comment('Часовой пояс автосалона (UTC offset, например +05:00)');
        });
    }

    public function down(): void
    {
        Schema::table('auto_dealerships', function (Blueprint $table) {
            $table->dropColumn('timezone');
        });
    }
};
