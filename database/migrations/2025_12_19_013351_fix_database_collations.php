<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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

        // Corrigir collations inconsistentes no banco de dados
        // Isso resolve o erro "Illegal mix of collations"

        // 1. Corrigir collation da tabela agents e suas colunas (problema principal)
        DB::statement('ALTER TABLE agents MODIFY COLUMN uuid VARCHAR(191) COLLATE utf8mb4_unicode_ci');
        DB::statement('ALTER TABLE agents MODIFY COLUMN user_id VARCHAR(191) COLLATE utf8mb4_unicode_ci');
        DB::statement('ALTER TABLE agents COLLATE utf8mb4_unicode_ci');

        // 2. Corrigir collation da tabela plan_user (está em latin1_swedish_ci)
        DB::statement('ALTER TABLE plan_user MODIFY COLUMN user_id VARCHAR(191) COLLATE utf8mb4_unicode_ci');
        DB::statement('ALTER TABLE plan_user COLLATE utf8mb4_unicode_ci');

        // 3. Garantir que whatsapp_instances esteja consistente
        DB::statement('ALTER TABLE whatsapp_instances MODIFY COLUMN uuid VARCHAR(191) COLLATE utf8mb4_unicode_ci');
        DB::statement('ALTER TABLE whatsapp_instances MODIFY COLUMN user_id VARCHAR(191) COLLATE utf8mb4_unicode_ci');
        DB::statement('ALTER TABLE whatsapp_instances MODIFY COLUMN agent_id VARCHAR(191) COLLATE utf8mb4_unicode_ci');
        DB::statement('ALTER TABLE whatsapp_instances COLLATE utf8mb4_unicode_ci');

        // 4. Corrigir collation da tabela users se necessário
        DB::statement('ALTER TABLE users MODIFY COLUMN uuid VARCHAR(191) COLLATE utf8mb4_unicode_ci');
        DB::statement('ALTER TABLE users COLLATE utf8mb4_unicode_ci');

        // 5. Corrigir collation da tabela plans se necessário
        DB::statement('ALTER TABLE plans COLLATE utf8mb4_unicode_ci');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Reverter para collations originais (se necessário)
        // Nota: Isso pode não funcionar perfeitamente dependendo do estado atual do banco

        // Reverter agents para utf8mb4_0900_ai_ci (collation original)
        DB::statement('ALTER TABLE agents MODIFY COLUMN uuid VARCHAR(255) COLLATE utf8mb4_0900_ai_ci');
        DB::statement('ALTER TABLE agents MODIFY COLUMN user_id VARCHAR(255) COLLATE utf8mb4_0900_ai_ci');

        // Nota: Não vamos reverter a collation da tabela agents pois pode causar outros problemas
    }
};
