<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $tables = [
            'user_list',
            'whatsapp_layouts',
            'whatsapp_dispatches',
            'whatsapp_dispatch_items',
        ];

        foreach ($tables as $table) {
            if (Schema::hasTable($table)) {
                DB::statement("ALTER TABLE {$table} ENGINE=InnoDB");
            }
        }

        if (Schema::hasTable('whatsapp_instances')) {
            DB::statement(
                "ALTER TABLE whatsapp_instances
                 MODIFY COLUMN status VARCHAR(32) NOT NULL DEFAULT 'disconnected'"
            );

            DB::statement('ALTER TABLE whatsapp_instances ENGINE=InnoDB');

            $hasIndex = DB::selectOne(
                "SELECT 1 FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'whatsapp_instances'
                   AND INDEX_NAME = 'whatsapp_instances_agent_id_index'
                 LIMIT 1"
            );

            if ($hasIndex) {
                DB::statement('ALTER TABLE whatsapp_instances DROP INDEX whatsapp_instances_agent_id_index');
            }

            $hasUniqueIndex = DB::selectOne(
                "SELECT 1 FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'whatsapp_instances'
                   AND INDEX_NAME = 'whatsapp_instances_agent_id_unique'
                 LIMIT 1"
            );

            if (!$hasUniqueIndex) {
                DB::statement(
                    'ALTER TABLE whatsapp_instances
                     ADD UNIQUE INDEX whatsapp_instances_agent_id_unique (agent_id)'
                );
            }
        }

        if (Schema::hasTable('whatsapp_dispatches')) {
            DB::statement(
                "ALTER TABLE whatsapp_dispatches
                 MODIFY COLUMN layout_id VARCHAR(191) NULL"
            );
        }

        if (Schema::hasTable('whatsapp_dispatch_items')) {
            if (!Schema::hasColumn('whatsapp_dispatch_items', 'processing_at')) {
                DB::statement(
                    'ALTER TABLE whatsapp_dispatch_items
                     ADD COLUMN processing_at TIMESTAMP NULL AFTER scheduled_at'
                );
            }

            if (!Schema::hasColumn('whatsapp_dispatch_items', 'claim_token')) {
                DB::statement(
                    'ALTER TABLE whatsapp_dispatch_items
                     ADD COLUMN claim_token VARCHAR(64) NULL AFTER processing_at'
                );
            }

            $hasClaimIndex = DB::selectOne(
                "SELECT 1 FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'whatsapp_dispatch_items'
                   AND INDEX_NAME = 'whatsapp_dispatch_items_claim_token_index'
                 LIMIT 1"
            );

            if (!$hasClaimIndex) {
                DB::statement(
                    'ALTER TABLE whatsapp_dispatch_items
                     ADD INDEX whatsapp_dispatch_items_claim_token_index (claim_token)'
                );
            }
        }

        $hasIndex = static function (string $table, string $index): bool {
            return (bool) DB::selectOne(
                'SELECT 1 FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1',
                [$table, $index]
            );
        };

        $hasForeignKey = static function (string $table, string $constraint): bool {
            return (bool) DB::selectOne(
                'SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
                 WHERE CONSTRAINT_SCHEMA = DATABASE()
                   AND TABLE_NAME = ?
                   AND CONSTRAINT_NAME = ?
                   AND CONSTRAINT_TYPE = ? LIMIT 1',
                [$table, $constraint, 'FOREIGN KEY']
            );
        };

        if (Schema::hasTable('whatsapp_dispatches')
            && Schema::hasTable('whatsapp_layouts')
            && !$hasForeignKey('whatsapp_dispatches', 'whatsapp_dispatches_layout_id_foreign')) {
            DB::statement(
                'ALTER TABLE whatsapp_dispatches
                 ADD CONSTRAINT whatsapp_dispatches_layout_id_foreign
                 FOREIGN KEY (layout_id) REFERENCES whatsapp_layouts (uuid)
                 ON DELETE SET NULL'
            );
        }

        if (Schema::hasTable('whatsapp_dispatch_items')
            && Schema::hasTable('whatsapp_dispatches')
            && !$hasForeignKey('whatsapp_dispatch_items', 'whatsapp_dispatch_items_dispatch_id_foreign')) {
            DB::statement(
                'ALTER TABLE whatsapp_dispatch_items
                 ADD CONSTRAINT whatsapp_dispatch_items_dispatch_id_foreign
                 FOREIGN KEY (dispatch_id) REFERENCES whatsapp_dispatches (id)
                 ON DELETE CASCADE'
            );
        }

        if (Schema::hasTable('whatsapp_dispatch_items')
            && Schema::hasTable('whatsapp_layouts')
            && !$hasForeignKey('whatsapp_dispatch_items', 'whatsapp_dispatch_items_layout_id_foreign')) {
            DB::statement(
                'ALTER TABLE whatsapp_dispatch_items
                 ADD CONSTRAINT whatsapp_dispatch_items_layout_id_foreign
                 FOREIGN KEY (layout_id) REFERENCES whatsapp_layouts (uuid)
                 ON DELETE SET NULL'
            );
        }

        if (Schema::hasTable('whatsapp_dispatch_items')
            && Schema::hasTable('user_list')
            && !$hasForeignKey('whatsapp_dispatch_items', 'whatsapp_dispatch_items_user_list_id_foreign')) {
            DB::statement(
                'ALTER TABLE whatsapp_dispatch_items
                 ADD CONSTRAINT whatsapp_dispatch_items_user_list_id_foreign
                 FOREIGN KEY (user_list_id) REFERENCES user_list (uuid)
                 ON DELETE RESTRICT'
            );
        }

        if (Schema::hasTable('whatsapp_dispatches') && !$hasIndex('whatsapp_dispatches', 'whatsapp_dispatches_layout_id_index')) {
            DB::statement(
                'ALTER TABLE whatsapp_dispatches
                 ADD INDEX whatsapp_dispatches_layout_id_index (layout_id)'
            );
        }

        if (Schema::hasTable('agents') && !$hasIndex('agents', 'agents_uuid_index')) {
            DB::statement('ALTER TABLE agents ADD INDEX agents_uuid_index (uuid)');
        }

        if (Schema::hasTable('users') && !$hasIndex('users', 'users_uuid_index')) {
            DB::statement('ALTER TABLE users ADD INDEX users_uuid_index (uuid)');
        }
    }

    public function down(): void
    {
        // Não desfazer a conversão para InnoDB nem restaurar o enum inconsistente.
    }
};
