<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('settings', 'sms_provider')) {
            Schema::table('settings', function (Blueprint $table): void {
                $table->string('sms_provider', 80)->default('comprarsms')->after('zapi_token');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('settings', 'sms_provider')) {
            Schema::table('settings', function (Blueprint $table): void {
                $table->dropColumn('sms_provider');
            });
        }
    }
};
