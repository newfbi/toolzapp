<?php

use Illuminate\Database\Migrations\Migration;
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

        $this->fixAllTableCollations();
        $this->fixAllColumnCollations();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Não é possível reverter facilmente, pois pode causar perda de dados
        // Em produção, seria necessário fazer backup antes
    }

    /**
     * Corrige collations de todas as tabelas
     */
    private function fixAllTableCollations(): void
    {
        $problematicTables = [
            'commission_log' => 'utf8mb4_general_ci',
            'payments' => 'utf8_unicode_ci',
            'saques' => 'utf8_unicode_ci',
            'settings' => 'utf8mb4_0900_ai_ci',
            'webhook_logs' => 'utf8mb4_general_ci'
        ];

        foreach ($problematicTables as $table => $currentCollation) {
            try {
                // Verificar se a tabela existe
                $tableExists = DB::select("SHOW TABLES LIKE ?", [$table]);

                if (!empty($tableExists)) {
                    // Alterar collation da tabela
                    DB::statement("ALTER TABLE `$table` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                    echo "✅ Tabela $table corrigida\n";
                }
            } catch (\Exception $e) {
                echo "❌ Erro ao corrigir tabela $table: " . $e->getMessage() . "\n";
            }
        }
    }

    /**
     * Corrige collations de todas as colunas
     */
    private function fixAllColumnCollations(): void
    {
        $wrongCollations = ['utf8mb4_0900_ai_ci', 'utf8mb4_general_ci', 'utf8_unicode_ci'];

        foreach ($wrongCollations as $wrongCollation) {
            try {
                // Buscar todas as colunas com collation problemática
                $columns = DB::select("
                    SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE
                    FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE()
                    AND COLLATION_NAME = ?
                    AND DATA_TYPE IN ('varchar', 'text', 'mediumtext', 'longtext', 'char')
                ", [$wrongCollation]);

                foreach ($columns as $column) {
                    try {
                        $table = $column->TABLE_NAME;
                        $columnName = $column->COLUMN_NAME;
                        $dataType = $column->DATA_TYPE;

                        // Determinar o tipo correto baseado no data_type
                        $typeDefinition = $this->getColumnTypeDefinition($dataType, $table, $columnName);

                        // Alterar collation da coluna
                        DB::statement("ALTER TABLE `$table` MODIFY COLUMN `$columnName` $typeDefinition CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                        echo "✅ Coluna $table.$columnName corrigida\n";
                    } catch (\Exception $e) {
                        echo "❌ Erro ao corrigir coluna $column->TABLE_NAME.$column->COLUMN_NAME: " . $e->getMessage() . "\n";
                    }
                }
            } catch (\Exception $e) {
                echo "❌ Erro ao buscar colunas com collation $wrongCollation: " . $e->getMessage() . "\n";
            }
        }
    }

    /**
     * Obtém a definição completa do tipo da coluna
     */
    private function getColumnTypeDefinition(string $dataType, string $table, string $column): string
    {
        try {
            $columnInfo = DB::select("
                SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA
                FROM information_schema.COLUMNS
                WHERE TABLE_NAME = ? AND COLUMN_NAME = ?
            ", [$table, $column]);

            if (empty($columnInfo)) {
                return strtoupper($dataType) . '(255)'; // Fallback
            }

            $info = $columnInfo[0];
            $definition = $info->COLUMN_TYPE;

            // Adicionar NULL/NOT NULL
            if ($info->IS_NULLABLE === 'NO') {
                $definition .= ' NOT NULL';
            }

            // Adicionar DEFAULT se existir
            if ($info->COLUMN_DEFAULT !== null) {
                $definition .= ' DEFAULT \'' . addslashes($info->COLUMN_DEFAULT) . '\'';
            }

            // Adicionar EXTRA (AUTO_INCREMENT, etc)
            if (!empty($info->EXTRA)) {
                $definition .= ' ' . $info->EXTRA;
            }

            return $definition;
        } catch (\Exception $e) {
            // Fallback para tipos comuns
            $types = [
                'varchar' => 'VARCHAR(255)',
                'text' => 'TEXT',
                'mediumtext' => 'MEDIUMTEXT',
                'longtext' => 'LONGTEXT',
                'char' => 'CHAR(255)'
            ];

            return $types[$dataType] ?? 'VARCHAR(255)';
        }
    }
};
