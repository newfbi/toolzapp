<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $indexes = [
            'agents' => ['user_id' => 'agents_user_id_index'],
            'chats' => ['uuid' => 'chats_uuid_index'],
        ];

        foreach ($indexes as $table => $columns) {
            if (!Schema::hasTable($table)) {
                continue;
            }

            $existing = collect(Schema::getIndexes($table))->pluck('name')->all();

            foreach ($columns as $column => $indexName) {
                if (!Schema::hasColumn($table, $column) || in_array($indexName, $existing, true)) {
                    continue;
                }

                Schema::table($table, function (Blueprint $blueprint) use ($column, $indexName): void {
                    $blueprint->index($column, $indexName);
                });
            }
        }
    }

    public function down(): void
    {
        foreach ([
            'agents' => 'agents_user_id_index',
            'chats' => 'chats_uuid_index',
        ] as $table => $indexName) {
            if (!Schema::hasTable($table)) {
                continue;
            }

            $existing = collect(Schema::getIndexes($table))->pluck('name')->all();
            if (!in_array($indexName, $existing, true)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($indexName): void {
                $blueprint->dropIndex($indexName);
            });
        }
    }
};
