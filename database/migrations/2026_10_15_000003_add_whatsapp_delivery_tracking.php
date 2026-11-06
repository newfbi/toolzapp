<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('whatsapp_dispatch_items')) {
            Schema::table('whatsapp_dispatch_items', function (Blueprint $table): void {
                if (!Schema::hasColumn('whatsapp_dispatch_items', 'whatsapp_message_id')) {
                    $table->string('whatsapp_message_id', 128)->nullable()->after('phone');
                }
                if (!Schema::hasColumn('whatsapp_dispatch_items', 'delivery_status')) {
                    $table->string('delivery_status', 24)->nullable()->after('whatsapp_message_id');
                }
                if (!Schema::hasColumn('whatsapp_dispatch_items', 'delivered_at')) {
                    $table->timestamp('delivered_at')->nullable()->after('sent_at');
                }
                if (!Schema::hasColumn('whatsapp_dispatch_items', 'read_at')) {
                    $table->timestamp('read_at')->nullable()->after('delivered_at');
                }
            });

            $indexes = collect(Schema::getIndexes('whatsapp_dispatch_items'))
                ->pluck('name')
                ->all();
            if (!in_array('whatsapp_dispatch_items_message_id_index', $indexes, true)) {
                Schema::table('whatsapp_dispatch_items', function (Blueprint $table): void {
                    $table->index('whatsapp_message_id', 'whatsapp_dispatch_items_message_id_index');
                });
            }

            DB::table('whatsapp_dispatch_items')
                ->where('status', 'sent')
                ->whereNull('delivery_status')
                ->update(['delivery_status' => 'unknown']);
        }

        if (Schema::hasTable('messages')) {
            Schema::table('messages', function (Blueprint $table): void {
                if (!Schema::hasColumn('messages', 'delivery_status')) {
                    $table->string('delivery_status', 24)->nullable()->after('whatsapp_message_id');
                }
                if (!Schema::hasColumn('messages', 'delivered_at')) {
                    $table->timestamp('delivered_at')->nullable()->after('delivery_status');
                }
                if (!Schema::hasColumn('messages', 'read_at')) {
                    $table->timestamp('read_at')->nullable()->after('delivered_at');
                }
            });

            DB::table('messages')
                ->where('direction', 'output')
                ->whereNull('delivery_status')
                ->update(['delivery_status' => 'unknown']);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('whatsapp_dispatch_items')) {
            $indexes = collect(Schema::getIndexes('whatsapp_dispatch_items'))
                ->pluck('name')
                ->all();
            Schema::table('whatsapp_dispatch_items', function (Blueprint $table) use ($indexes): void {
                if (in_array('whatsapp_dispatch_items_message_id_index', $indexes, true)) {
                    $table->dropIndex('whatsapp_dispatch_items_message_id_index');
                }
                if (Schema::hasColumn('whatsapp_dispatch_items', 'read_at')) {
                    $table->dropColumn('read_at');
                }
                if (Schema::hasColumn('whatsapp_dispatch_items', 'delivered_at')) {
                    $table->dropColumn('delivered_at');
                }
                if (Schema::hasColumn('whatsapp_dispatch_items', 'delivery_status')) {
                    $table->dropColumn('delivery_status');
                }
                if (Schema::hasColumn('whatsapp_dispatch_items', 'whatsapp_message_id')) {
                    $table->dropColumn('whatsapp_message_id');
                }
            });
        }

        if (Schema::hasTable('messages')) {
            Schema::table('messages', function (Blueprint $table): void {
                if (Schema::hasColumn('messages', 'read_at')) {
                    $table->dropColumn('read_at');
                }
                if (Schema::hasColumn('messages', 'delivered_at')) {
                    $table->dropColumn('delivered_at');
                }
                if (Schema::hasColumn('messages', 'delivery_status')) {
                    $table->dropColumn('delivery_status');
                }
            });
        }
    }
};
