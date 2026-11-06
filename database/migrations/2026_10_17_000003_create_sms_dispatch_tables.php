<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_dispatches', function (Blueprint $table): void {
            $table->id();
            $table->string('uuid', 191)->unique();
            $table->string('user_id', 191)->index();
            $table->string('layout_id', 191)->nullable()->index();
            $table->unsignedInteger('total_items')->default(0);
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('delivered_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->decimal('provider_cost', 12, 4)->nullable();
            $table->string('status', 20)->default('pending')->index();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('last_progress_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('sms_dispatch_items', function (Blueprint $table): void {
            $table->id();
            $table->string('uuid', 191)->unique();
            $table->foreignId('dispatch_id')->constrained('sms_dispatches')->cascadeOnDelete();
            $table->string('layout_id', 191)->nullable()->index();
            $table->string('user_list_id', 191)->index();
            $table->string('phone', 30)->index();
            $table->string('message', 160);
            $table->string('provider_batch_id', 191)->nullable()->index();
            $table->string('provider_message_id', 191)->nullable()->index();
            $table->string('provider_status', 40)->nullable();
            $table->string('status', 20)->default('pending')->index();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamp('scheduled_at')->nullable()->index();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'scheduled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_dispatch_items');
        Schema::dropIfExists('sms_dispatches');
    }
};
