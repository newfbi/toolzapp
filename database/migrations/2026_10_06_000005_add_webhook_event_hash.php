<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('webhook_logs')) {
            return;
        }

        if (!Schema::hasColumn('webhook_logs', 'event_hash')) {
            Schema::table('webhook_logs', function (Blueprint $table): void {
                $table->string('event_hash', 64)->nullable()->after('event');
                $table->index(['origin', 'event_hash'], 'webhook_logs_origin_event_hash_index');
            });
        }

        if (!Schema::hasColumn('webhook_logs', 'period')) {
            Schema::table('webhook_logs', function (Blueprint $table): void {
                $table->unsignedInteger('period')->nullable()->after('product');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('webhook_logs') || !Schema::hasColumn('webhook_logs', 'event_hash')) {
            return;
        }

        Schema::table('webhook_logs', function (Blueprint $table): void {
            $table->dropIndex('webhook_logs_origin_event_hash_index');
            $table->dropColumn(['event_hash', 'period']);
        });
    }
};
