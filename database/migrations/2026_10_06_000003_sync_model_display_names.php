<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('agents')) {
            DB::table('agents')
                ->where('agent_type', 'model_default')
                ->where('name', 'like', 'Modelo:%')
                ->get(['id', 'model'])
                ->each(function ($agent): void {
                    DB::table('agents')
                        ->where('id', $agent->id)
                        ->update(['name' => 'Modelo: ' . $agent->model]);
                });
        }

        if (Schema::hasTable('messages')) {
            DB::table('messages')
                ->where('name', 'like', 'Modelo: gpt-%')
                ->update(['name' => 'Modelo: gpt-6.1-sol']);
        }
    }

    public function down(): void
    {
        // Os nomes legados não são restaurados.
    }
};
