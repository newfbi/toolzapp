<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sms_layouts')) {
            Schema::table('sms_layouts', function (Blueprint $table): void {
                $table->text('message')->change();
            });
        }
    }

    public function down(): void
    {
        // Não reduzir a coluna para varchar(160), pois isso poderia truncar
        // layouts concatenados já salvos.
    }
};
