<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_list', function (Blueprint $table) {
            $table->boolean('whatsapp_opt_in')->default(false)->after('status')->index();
        });
    }

    public function down(): void
    {
        Schema::table('user_list', function (Blueprint $table) {
            $table->dropIndex(['whatsapp_opt_in']);
            $table->dropColumn('whatsapp_opt_in');
        });
    }
};
