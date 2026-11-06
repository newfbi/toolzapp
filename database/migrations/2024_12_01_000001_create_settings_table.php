<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('settings')) {
            Schema::create('settings', function (Blueprint $table) {
                $table->increments('id');
                $table->string('admin');
                $table->string('api_key');
                $table->boolean('use_system_api')->default(false);
                $table->string('supremo_api_key')->nullable();
                $table->string('supremo_webhook_secret')->nullable();
                $table->string('zapi_token', 255)->nullable();
                $table->float('credits_bonus')->default(0);
                $table->float('recharge')->default(0);
                $table->integer('commission')->default(0);
                $table->float('token_value')->default(0);
                $table->integer('token_package')->default(0);
                $table->integer('max_characters')->default(0);
                $table->longText('nameUser');
                $table->longText('saudacao');
                $table->longText('conversion');
                $table->text('personal_skills')->nullable();
                $table->string('supremo_enabled')->default('false');
                $table->string('supremo_default_source')->default('WhatsApp Bot');
                $table->string('supremo_default_campaign')->nullable();
                $table->string('supremo_auto_sync')->default('false');
                $table->integer('supremo_min_score')->default(60);
                $table->boolean('supremo_sync_notes')->default(false);
                $table->timestamps();
            });
        }

        // A aplicação espera uma configuração-base, inclusive na tela de cadastro.
        // Não copiamos nenhuma credencial do dump; a chave fica vazia até ser configurada.
        if (!DB::table('settings')->exists()) {
            DB::table('settings')->insert([
                'admin' => 'system',
                'api_key' => '',
                'use_system_api' => false,
                'credits_bonus' => 0,
                'recharge' => 0,
                'commission' => 0,
                'token_value' => 0,
                'token_package' => 0,
                'max_characters' => 0,
                'nameUser' => '',
                'saudacao' => '',
                'conversion' => '',
                'supremo_enabled' => 'false',
                'supremo_default_source' => 'WhatsApp Bot',
                'supremo_auto_sync' => 'false',
                'supremo_min_score' => 60,
                'supremo_sync_notes' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
