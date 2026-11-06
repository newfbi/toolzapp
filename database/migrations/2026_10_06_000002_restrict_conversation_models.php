<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const DEFAULT_MODEL = 'gpt-6.1-sol';

    private const ALLOWED_MODELS = [
        'gpt-6.1-sol',
        'gpt-6-luna',
    ];

    public function up(): void
    {
        if (Schema::hasTable('agents') && Schema::hasColumn('agents', 'model')) {
            DB::table('agents')
                ->where(function ($query) {
                    $query->whereNull('model')
                        ->orWhere('model', '')
                        ->orWhereNotIn('model', self::ALLOWED_MODELS);
                })
                ->update(['model' => self::DEFAULT_MODEL]);

            if (DB::getDriverName() === 'mysql') {
                DB::statement("ALTER TABLE agents MODIFY COLUMN model VARCHAR(191) NOT NULL DEFAULT '" . self::DEFAULT_MODEL . "'");
            }
        }

        if (Schema::hasTable('platforms')) {
            DB::table('platforms')
                ->where('slug', 'openai')
                ->update([
                    'models' => json_encode([
                        'gpt-6.1-sol' => 'GPT-6.1 Sol',
                        'gpt-6-luna' => 'GPT-6 Luna',
                    ], JSON_UNESCAPED_UNICODE),
                    'pricing' => json_encode([], JSON_UNESCAPED_UNICODE),
                    'updated_at' => now(),
                ]);
        }

        if (Schema::hasTable('chats') && !Schema::hasColumn('chats', 'selected_model')) {
            Schema::table('chats', function (Blueprint $table) {
                $table->string('selected_model')->nullable()->after('agent_id');
                $table->index('selected_model');
            });
        }

        if (Schema::hasTable('chats') && Schema::hasTable('agents')) {
            DB::table('chats')
                ->whereNull('selected_model')
                ->orderBy('id')
                ->get(['id', 'agent_id'])
                ->each(function ($chat): void {
                    $model = DB::table('agents')
                        ->where('uuid', $chat->agent_id)
                        ->where('agent_type', 'model_default')
                        ->value('model');

                    if ($model) {
                        DB::table('chats')
                            ->where('id', $chat->id)
                            ->update(['selected_model' => $model]);
                    }
                });
        }

        if (Schema::hasTable('messages')) {
            DB::table('messages')
                ->where('name', 'like', 'Modelo: gpt-%')
                ->update(['name' => 'Modelo: ' . self::DEFAULT_MODEL]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('chats') && Schema::hasColumn('chats', 'selected_model')) {
            Schema::table('chats', function (Blueprint $table) {
                $table->dropIndex(['selected_model']);
                $table->dropColumn('selected_model');
            });
        }
    }
};
