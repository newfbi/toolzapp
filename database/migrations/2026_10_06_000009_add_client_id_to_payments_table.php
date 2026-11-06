<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('payments') || Schema::hasColumn('payments', 'client_id')) {
            return;
        }

        Schema::table('payments', function (Blueprint $table): void {
            $table->string('client_id')->nullable()->index()->after('user_id');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('payments') || !Schema::hasColumn('payments', 'client_id')) {
            return;
        }

        Schema::table('payments', function (Blueprint $table): void {
            $table->dropIndex(['client_id']);
            $table->dropColumn('client_id');
        });
    }
};
