<?php

namespace App\Models;

use App\Support\AiModelCatalog;
use Illuminate\Database\Eloquent\Model;

class Chats extends Model
{
    protected $fillable = [
        'uuid',
        'user_id',
        'agent_id',
        'chat_id',
        'contact_lid', // 🆕 LID do contato (@lid) para Baileys v7+
        'phone',
        'name',
        'status',
        'conversion',
        'received_prod',
        'is_pause',
        'last_message',
        'time',
        'selected_model',
    ];

    protected $casts = [
        'is_pause' => 'string',
        'received_prod' => 'boolean',
    ];

    /**
     * Mantém o modelo selecionado do chat dentro do catálogo vigente.
     * Chats que não usam modelo podem continuar com valor nulo.
     */
    public function setSelectedModelAttribute($value): void
    {
        if ($value === null || trim((string) $value) === '') {
            $this->attributes['selected_model'] = null;

            return;
        }

        $this->attributes['selected_model'] = AiModelCatalog::normalize((string) $value);
    }

    /**
     * Evita que um chat antigo exponha um ID legado durante a leitura.
     */
    public function getSelectedModelAttribute($value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return AiModelCatalog::normalize((string) $value);
    }

    public function scopeConverted($query)
    {
        return $query->where(function ($q) {
            $q->where('conversion', 'true')
                ->orWhere('conversion', true);
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'uuid');
    }

    public function agent()
    {
        return $this->belongsTo(Agents::class, 'agent_id', 'uuid');
    }

    public function messages()
    {
        return $this->hasMany(Messages::class, 'chat_id', 'uuid');
    }

    /**
     * Relacionamento com MemoryStream (memória do chat)
     * Cada chat tem um único MemoryStream que armazena o contexto e conhecimento
     */
    public function memory()
    {
        return $this->hasOne(MemoryStream::class, 'chat_id', 'uuid');
    }

    /**
     * Accessor para lead_qualification - retorna os dados já decodificados do JSON
     *
     * Uso: $chat->lead_qualification
     * Retorna: ['qualified' => bool, 'score' => int, 'reasons' => array, 'analysis' => string]
     *
     * @return array|null Dados da qualificação do lead ou null se não existir
     */
    public function getLeadQualificationAttribute()
    {
        // Carrega a memória se ainda não estiver carregada
        if (!$this->relationLoaded('memory')) {
            $this->load('memory');
        }

        $memory = $this->getRelation('memory');

        if (!$memory || empty($memory->lead_qualification)) {
            return null;
        }

        // Se já for array (devido ao cast), retorna diretamente
        if (is_array($memory->lead_qualification)) {
            return $memory->lead_qualification;
        }

        // Se for string, decodifica o JSON
        if (is_string($memory->lead_qualification)) {
            $decoded = json_decode($memory->lead_qualification, true);
            return json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? $decoded : null;
        }

        return null;
    }

    /**
     * Obtém ou cria a memória do chat
     *
     * @return MemoryStream
     */
    public function getOrCreateMemory(): MemoryStream
    {
        if (!$this->relationLoaded('memory')) {
            $this->load('memory');
        }

        $memory = $this->getRelation('memory');

        if (!$memory) {
            $memory = MemoryStream::firstOrCreate(
                ['chat_id' => $this->uuid],
                [
                    'user_id' => $this->user_id,
                    'agent_id' => $this->agent_id,
                    'conversation_id' => $this->uuid,
                    'conversation_log' => ['history' => []],
                    'knowledge_base' => [],
                ]
            );
            $this->setRelation('memory', $memory);
        }

        return $memory;
    }

    /**
     * Verifica se o chat tem memória
     *
     * @return bool
     */
    public function hasMemory(): bool
    {
        if (!$this->relationLoaded('memory')) {
            $this->load('memory');
        }

        $memory = $this->getRelation('memory');
        return $memory !== null;
    }

    /**
     * Obtém o humor atual do usuário neste chat
     *
     * @return string
     */
    public function getCurrentMood(): string
    {
        $memory = $this->getOrCreateMemory();
        return $memory->current_mood;
    }

    /**
     * Obtém número de episódios na memória
     *
     * @return int
     */
    public function getEpisodesCount(): int
    {
        if (!$this->hasMemory()) {
            return 0;
        }

        $memory = $this->getOrCreateMemory();
        return $memory->getEpisodesCount();
    }

    /**
     * Obtém histórico de mensagens da memória
     *
     * @return array
     */
    public function getMemoryHistory(): array
    {
        $memory = $this->getOrCreateMemory();
        return $memory->history;
    }
}
