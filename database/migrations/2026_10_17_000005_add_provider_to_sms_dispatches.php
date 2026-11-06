<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('sms_dispatches', 'provider')) {
            Schema::table('sms_dispatches', function (Blueprint $table): void {
                $table->string('provider', 80)->default('comprarsms')->after('user_id');
                $table->index(['provider', 'status'], 'sms_dispatches_provider_status_index');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('sms_dispatches', 'provider')) {
            Schema::table('sms_dispatches', function (Blueprint $table): void {
                $table->dropIndex('sms_dispatches_provider_status_index');
                $table->dropColumn('provider');
            });
        }
    }
};
