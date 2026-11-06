<?php

use App\Models\Settings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sms_dispatch_items')) {
            Schema::table('sms_dispatch_items', function (Blueprint $table): void {
                $table->text('message')->change();
            });
        }

        // Migra apenas a seleção anterior criada pelo fluxo padrão. Uma
        // escolha manual de outro provedor não é sobrescrita.
        if (Schema::hasColumn('settings', 'sms_provider')) {
            Settings::query()
                ->whereIn('sms_provider', ['comprarsms', ''])
                ->update(['sms_provider' => 'infobip']);
        }
    }

    public function down(): void
    {
        // A coluna text evita perda de mensagens concatenadas e não deve ser
        // reduzida novamente para varchar(160) em um rollback operacional.
    }
};
