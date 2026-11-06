<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('payments')) {
            return;
        }

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->string('user_id')->index();
            $table->string('spon_id');
            $table->string('reference', 10)->nullable();
            $table->string('product_name')->nullable();
            $table->string('billingType')->nullable();
            $table->string('tipo')->nullable();
            $table->string('gateway')->default('asaas');
            $table->date('validade')->nullable();
            $table->decimal('valor', 12, 2)->default(0);
            $table->string('status')->default('aberto');
            $table->string('codigo', 70)->nullable()->unique();
            $table->text('pix_code')->nullable();
            $table->longText('qr_code')->nullable();
            $table->date('data_compra')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
