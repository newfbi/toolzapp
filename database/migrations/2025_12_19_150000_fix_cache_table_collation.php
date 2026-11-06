<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // Verificar se a tabela cache existe
        if (Schema::hasTable('cache')) {
            // Alterar collation da tabela cache para utf8mb4_unicode_ci
            DB::statement('ALTER TABLE cache CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

            // Alterar collation específica da coluna value se necessário
            DB::statement('ALTER TABLE cache MODIFY COLUMN `value` MEDIUMTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        }

        // Verificar se a tabela cache_locks existe
        if (Schema::hasTable('cache_locks')) {
            // Alterar collation da tabela cache_locks para utf8mb4_unicode_ci
            DB::statement('ALTER TABLE cache_locks CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

            // Alterar collation específica da coluna owner se necessário
            DB::statement('ALTER TABLE cache_locks MODIFY COLUMN `owner` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Reverter para collation padrão (se necessário)
        // Nota: Isso pode causar perda de dados se houver caracteres especiais

        if (Schema::hasTable('cache')) {
            DB::statement('ALTER TABLE cache CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
            DB::statement('ALTER TABLE cache MODIFY COLUMN `value` MEDIUMTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
        }

        if (Schema::hasTable('cache_locks')) {
            DB::statement('ALTER TABLE cache_locks CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
            DB::statement('ALTER TABLE cache_locks MODIFY COLUMN `owner` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
        }
    }
};
