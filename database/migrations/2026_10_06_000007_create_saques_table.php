<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('saques')) {
            return;
        }

        Schema::create('saques', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('uuid')->unique();
            $table->string('user_id')->index();
            $table->double('valor', 15, 2);
            $table->string('chave_pix')->nullable();
            $table->string('tipo')->default('comissao');
            $table->string('comprovante')->nullable();
            $table->string('status')->default('aberto');
            $table->date('data');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saques');
    }
};
