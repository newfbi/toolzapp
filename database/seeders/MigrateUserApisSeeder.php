<?php

namespace Database\Seeders;

use App\Models\UserApi;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class MigrateUserApisSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Migra APIs existentes para o novo formato com plataformas
     */
    public function run(): void
    {
        // Buscar APIs que ainda usam o formato antigo (sem platform_slug)
        $oldApis = UserApi::whereNull('platform_slug')->get();

        foreach ($oldApis as $api) {
            // Se tiver apenas api_key (formato antigo), assumir que é OpenAI
            if (!empty($api->api_key)) {
                $api->update([
                    'platform_slug' => 'openai',
                    'is_active' => true
                ]);

                $this->command->info("Migrada API do usuário {$api->user_id} para OpenAI");
            }
        }

        $this->command->info("Migração concluída. Total de APIs migradas: {$oldApis->count()}");
    }
}
