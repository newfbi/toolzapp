<?php

namespace App\Models;

use App\Support\AiModelCatalog;
use Illuminate\Database\Eloquent\Model;

class Agents extends Model
{
    protected $fillable = [
        'uuid',
        'user_id',
        'assistant_id',
        'phone',
        'phone_id',
        'name',
        'model',
        'channel',
        'personality',
        'max_characters',
        'autonomy',
        'general_training',
        'training',
        'instructions',
        'training_condensed',
        'training_optimized',
        'gender',
        'faq',
        'closing',
        'lead_interval',
        'negative_tags',
        'response_settings',
        'agent_type',
        'conversation_endings',
        'auto_followup',
        'followup_delay',
        'audio',
        'midia',
        'is_personal',
        'saudacao',
        'nameUser',
        'credit_usage',
        'context_window_size',
        'forbidden_terms',
        'configurations',
        'is_trial',
        'avatar',
        'status'
    ];

    protected $casts = [
        'response_settings' => 'array',
        'conversation_endings' => 'array',
        'configurations' => 'array',
        'audio' => 'boolean',
        'midia' => 'boolean',
        'is_personal' => 'boolean',
        'saudacao' => 'boolean',
        'nameUser' => 'boolean',
        'auto_followup' => 'boolean'
    ];

    /**
     * Garante que gravações feitas fora dos formulários também não reintroduzam
     * modelos antigos ou IDs arbitrários no fluxo de conversação.
     */
    public function setModelAttribute($value): void
    {
        $this->attributes['model'] = AiModelCatalog::normalize(
            is_string($value) ? $value : null
        );
    }

    /**
     * Normaliza também leituras de registros antigos que possam existir em
     * uma réplica, importação ou cache antes da migração de dados.
     */
    public function getModelAttribute($value): string
    {
        return AiModelCatalog::normalize(is_string($value) ? $value : null);
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'uuid');
    }

    public function profile()
    {
        return $this->hasOne(AgentProfile::class, 'expertise', 'expertise');
    }

    public function chats()
    {
        return $this->hasMany(Chats::class, 'agent_id', 'uuid');
    }

    public function messages()
    {
        return $this->hasMany(Messages::class, 'agent_id', 'uuid');
    }

    public function api()
    {
        return $this->hasOne(UserApi::class, 'user_id', 'user_id');
    }

    public function whatsappInstance()
    {
        return $this->hasOne(WhatsappInstance::class, 'agent_id', 'uuid');
    }
}
