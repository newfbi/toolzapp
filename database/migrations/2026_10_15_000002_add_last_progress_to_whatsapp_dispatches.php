<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_dispatches', function (Blueprint $table) {
            $table->timestamp('last_progress_at')->nullable()->after('started_at')->index();
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_dispatches', function (Blueprint $table) {
            $table->dropIndex(['last_progress_at']);
            $table->dropColumn('last_progress_at');
        });
    }
};
