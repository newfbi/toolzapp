<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class MemoryStream extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'agent_id',
        'chat_id',
        'conversation_id',
        'status',
        'conversation_log',
        'knowledge_base',
        'last_consolidation_count',
        'lead_qualification',
        'last_qualification_analysis',
        'last_qualification_count',
        'last_compression_count',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'conversation_log' => 'array',
        'knowledge_base' => 'array',
        'lead_qualification' => 'array',
    ];

    /**
     * Relacionamento com Chat
     */
    public function chat()
    {
        return $this->belongsTo(Chats::class, 'chat_id', 'uuid');
    }

    /**
     * Relacionamento com Agent
     */
    public function agent()
    {
        return $this->belongsTo(Agents::class, 'agent_id', 'uuid');
    }

    /**
     * Relacionamento com User
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'uuid');
    }

    /**
     * Accessor para obter memória episódica de forma segura
     *
     * @return array Array de episódios
     */
    public function getEpisodicMemoryAttribute(): array
    {
        $knowledge = $this->knowledge_base ?? [];
        return $knowledge['episodic_memory'] ?? [];
    }

    /**
     * Accessor para obter memória episódica agrupada por categoria
     *
     * @return array Episódios agrupados por categoria
     */
    public function getEpisodicMemoryByCategoryAttribute(): array
    {
        $knowledge = $this->knowledge_base ?? [];
        return $knowledge['episodic_memory_by_category'] ?? [];
    }

    /**
     * Accessor para obter dados de mood tracking
     *
     * @return array Dados de rastreamento de humor
     */
    public function getMoodTrackingAttribute(): array
    {
        $knowledge = $this->knowledge_base ?? [];
        return $knowledge['mood_tracking'] ?? [
            'current_mood' => 'neutral',
            'mood_history' => [],
            'mood_patterns' => []
        ];
    }

    /**
     * Accessor para obter humor atual
     *
     * @return string Humor atual do usuário
     */
    public function getCurrentMoodAttribute(): string
    {
        $moodTracking = $this->mood_tracking;
        return $moodTracking['current_mood'] ?? 'neutral';
    }

    /**
     * Accessor para obter histórico de conversa formatado
     *
     * @return array Histórico de mensagens
     */
    public function getHistoryAttribute(): array
    {
        $log = $this->conversation_log ?? [];
        return $log['history'] ?? [];
    }

    /**
     * Accessor para obter informações pessoais do usuário
     *
     * @return array Informações pessoais
     */
    public function getPersonalInfoAttribute(): array
    {
        $knowledge = $this->knowledge_base ?? [];
        return $knowledge['informacoes_pessoais'] ?? [];
    }

    /**
     * Accessor para obter qualificação de lead
     *
     * @return array|null Dados de qualificação ou null
     */
    public function getLeadQualificationDataAttribute(): ?array
    {
        $knowledge = $this->knowledge_base ?? [];
        return $knowledge['qualificacao_lead'] ?? null;
    }

    /**
     * Verifica se há memória episódica
     *
     * @return bool
     */
    public function hasEpisodicMemory(): bool
    {
        return !empty($this->episodic_memory);
    }

    /**
     * Obtém número total de episódios
     *
     * @return int
     */
    public function getEpisodesCount(): int
    {
        return count($this->episodic_memory);
    }

    /**
     * Obtém número total de mensagens no histórico
     *
     * @return int
     */
    public function getHistoryCount(): int
    {
        return count($this->history);
    }

    /**
     * Verifica se precisa consolidar conhecimento
     *
     * @param int $interval Intervalo de consolidação
     * @return bool
     */
    public function needsConsolidation(int $interval = 15): bool
    {
        $totalPairs = (int)floor($this->getHistoryCount() / 2);
        $lastConsolidation = (int)($this->last_consolidation_count ?? 0);
        return ($totalPairs - $lastConsolidation) >= $interval;
    }

    /**
     * Verifica se precisa comprimir histórico
     *
     * @param int $interval Intervalo de compressão
     * @return bool
     */
    public function needsCompression(int $interval = 50): bool
    {
        $totalMessages = $this->getHistoryCount();
        if ($totalMessages < $interval) {
            return false;
        }

        $lastCompression = (int)($this->last_compression_count ?? 0);
        return ($totalMessages - $lastCompression) >= $interval;
    }
}
