<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('user_list', 'sms_opt_in')) {
            Schema::table('user_list', function (Blueprint $table): void {
                $table->boolean('sms_opt_in')->default(false)->after('whatsapp_opt_in');
                $table->index('sms_opt_in', 'user_list_sms_opt_in_index');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('user_list', 'sms_opt_in')) {
            Schema::table('user_list', function (Blueprint $table): void {
                $table->dropIndex('user_list_sms_opt_in_index');
                $table->dropColumn('sms_opt_in');
            });
        }
    }
};
