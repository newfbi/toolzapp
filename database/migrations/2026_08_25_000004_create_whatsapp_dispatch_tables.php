<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Um disparo = uma ação do usuário (layout + instância + conjunto de leads)
        Schema::create('whatsapp_dispatches', function (Blueprint $table) {
            $table->id();
            $table->string('uuid', 191)->unique();
            $table->string('user_id', 191)->index();           // Dono (users.uuid)
            $table->uuid('layout_id')->nullable();             // whatsapp_layouts.uuid
            $table->string('agent_id', 191)->index();          // agents.uuid (instância emissora)
            $table->unsignedInteger('total_items')->default(0);
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->string('status', 20)->default('pending')->index(); // pending, processing, completed, cancelled, failed
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        // Cada lead agendado dentro de um disparo (a "fila" em si)
        Schema::create('whatsapp_dispatch_items', function (Blueprint $table) {
            $table->id();
            $table->string('uuid', 191)->unique();
            $table->foreignId('dispatch_id')->constrained('whatsapp_dispatches')->cascadeOnDelete();
            $table->string('user_list_id', 191)->index();      // user_list.uuid
            $table->string('phone', 30)->nullable()->index();  // Snapshot canônico no momento do disparo
            $table->string('status', 15)->default('pending')->index(); // pending, sent, failed, skipped
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamp('scheduled_at')->nullable()->index(); // Quando pode ser enviado (pacing)
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'scheduled_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('whatsapp_dispatch_items');
        Schema::dropIfExists('whatsapp_dispatches');
    }
};
