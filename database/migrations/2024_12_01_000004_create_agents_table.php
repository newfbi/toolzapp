<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('agents')) {
            return;
        }

        Schema::create('agents', function (Blueprint $table) {
            $table->increments('id');
            $table->string('uuid')->nullable()->index();
            $table->string('user_id')->nullable()->index();
            $table->string('assistant_id')->nullable();
            $table->string('phone')->nullable();
            $table->string('phone_id')->nullable();
            $table->string('name');
            $table->string('model')->default('gpt-6.1-sol');
            $table->string('channel')->default('website');
            $table->string('personality')->default('friendly');
            $table->integer('max_characters')->default(500);
            $table->integer('context_window_size')->nullable()->default(15);
            $table->text('forbidden_terms')->nullable();
            $table->json('configurations')->nullable();
            $table->longText('general_training')->nullable();
            $table->text('training')->nullable();
            $table->text('instructions')->nullable();
            $table->longText('training_condensed')->nullable();
            $table->longText('training_optimized')->nullable();
            $table->string('agent_type')->default('general');
            $table->text('conversation_endings')->nullable();
            $table->boolean('auto_followup')->default(false);
            $table->integer('followup_delay')->default(5);
            $table->string('gender')->default('neutro');
            $table->longText('personal_skills')->nullable();
            $table->text('negative_tags')->nullable();
            $table->text('faq')->nullable();
            $table->text('closing')->nullable();
            $table->integer('lead_interval')->default(0);
            $table->string('expertise')->default('vendas');
            $table->string('autonomy')->default('moderada');
            $table->string('resp_time')->default('disabled');
            $table->float('credit_usage')->default(0);
            $table->boolean('audio')->default(false);
            $table->boolean('midia')->default(false);
            $table->boolean('is_personal')->default(false);
            $table->boolean('saudacao')->default(false);
            $table->boolean('nameUser')->default(false);
            $table->string('behavior')->default('disabled');
            $table->string('avatar', 255)->nullable();
            $table->boolean('is_trial')->default(false);
            $table->string('status')->default('draft');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agents');
    }
};
