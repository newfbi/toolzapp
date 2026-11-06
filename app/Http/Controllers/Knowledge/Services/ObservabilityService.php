<?php

namespace App\Http\Controllers\Knowledge\Services;

use App\Models\Chats;
use App\Models\Agents;
use Illuminate\Support\Facades\Log;

/**
 * Observability Service - Observabilidade e Rastreamento.
 *
 * Responsabilidade: Fornecer logs estruturados, métricas e tracing
 * para monitoramento do sistema de memória e comportamento do agente.
 *
 * Registra:
 * - IDs de episódios usados
 * - Intenções detectadas
 * - Compressões aplicadas
 * - Reflexões e scores
 * - Alertas de anomalias
 */
class ObservabilityService
{
    /**
     * Registra métricas de uma interação completa.
     *
     * @param Chats $chat
     * @param Agents $agent
     * @param array $metrics Métricas da interação
     */
    public function logInteraction(
        Chats $chat,
        Agents $agent,
        array $metrics
    ): void {
        $logData = [
            'type' => 'interaction',
            'chat_id' => $chat->uuid ?? null,
            'agent_id' => $agent->uuid ?? null,
            'agent_name' => $agent->name ?? null,
            'timestamp' => now()->toIso8601String(),
            'metrics' => [
                'model' => $metrics['model'] ?? null,
                'tokens' => [
                    'total' => $metrics['tokens'] ?? 0,
                    'prompt' => $metrics['prompt_tokens'] ?? 0,
                    'completion' => $metrics['completion_tokens'] ?? 0,
                ],
                'intent' => $metrics['intent'] ?? null,
                'mood' => $metrics['mood'] ?? null,
                'episodes_used' => $metrics['episodes_used'] ?? [],
                'reflection' => [
                    'store' => $metrics['reflection']['store'] ?? false,
                    'should_improve' => $metrics['reflection']['should_improve'] ?? false,
                ],
                'compression_applied' => $metrics['compression_applied'] ?? false,
                'persona_drift_score' => $metrics['persona_drift_score'] ?? null,
            ]
        ];
    }

    /**
     * Registra uso de episódios na memória.
     *
     * @param Chats $chat
     * @param array $episodes Episódios usados
     */
    public function logEpisodesUsed(Chats $chat, array $episodes): void
    {
        $episodeIds = array_map(function ($episode) {
            return $episode['episode_id'] ?? null;
        }, $episodes);
    }

    /**
     * Registra compressão de memória.
     *
     * @param Chats $chat
     * @param array $compressionData Dados da compressão
     */
    public function logCompression(Chats $chat, array $compressionData): void
    {
        Log::info('Compressão de memória aplicada', [
            'chat_id' => $chat->uuid ?? null,
            'original_count' => $compressionData['original_count'] ?? 0,
            'compressed_to' => $compressionData['compressed_to'] ?? 0,
            'kept_recent' => $compressionData['kept_recent'] ?? 0
        ]);
    }

    /**
     * Registra alerta de anomalia.
     *
     * @param string $type Tipo de alerta
     * @param Chats $chat
     * @param string $message Mensagem do alerta
     * @param array $context Contexto adicional
     */
    public function logAlert(string $type, Chats $chat, string $message, array $context = []): void
    {
        Log::warning("Knowledge Agent Alert: {$type}", array_merge([
            'chat_id' => $chat->uuid ?? null,
            'message' => $message,
            'timestamp' => now()->toIso8601String()
        ], $context));
    }

    /**
     * Verifica e registra alertas de anomalias.
     *
     * @param Chats $chat
     * @param Agents $agent
     * @param array $metrics Métricas da interação
     */
    public function checkAnomalies(Chats $chat, Agents $agent, array $metrics): void
    {
        // Alerta se auto-reflexão falhou consecutivamente
        if (isset($metrics['reflection_error']) && $metrics['reflection_error']) {
            $this->logAlert('reflection_error', $chat, 'Auto-reflexão falhou', [
                'agent_id' => $agent->uuid ?? null
            ]);
        }

        // Alerta se persona drift score muito baixo
        if (isset($metrics['persona_drift_score']) && $metrics['persona_drift_score'] < 0.6) {
            $this->logAlert('persona_drift', $chat, 'Persona drift detectado (score < 0.6)', [
                'agent_id' => $agent->uuid ?? null,
                'score' => $metrics['persona_drift_score']
            ]);
        }

        // Alerta se uso excessivo de tokens
        $totalTokens = $metrics['tokens'] ?? 0;
        if ($totalTokens > 8000) {
            $this->logAlert('high_token_usage', $chat, 'Uso excessivo de tokens', [
                'agent_id' => $agent->uuid ?? null,
                'tokens' => $totalTokens
            ]);
        }

        // Alerta se memória não foi atualizada após muitas interações
        if (isset($metrics['memory_not_updated_count']) && $metrics['memory_not_updated_count'] > 5) {
            $this->logAlert('memory_stale', $chat, 'Memória não atualizada há muitas interações', [
                'agent_id' => $agent->uuid ?? null,
                'count' => $metrics['memory_not_updated_count']
            ]);
        }
    }

    /**
     * Gera resumo de métricas para dashboard/logging.
     *
     * @param Chats $chat
     * @param array $metrics
     * @return array Resumo estruturado
     */
    public function generateMetricsSummary(Chats $chat, array $metrics): array
    {
        return [
            'chat_id' => $chat->uuid ?? null,
            'timestamp' => now()->toIso8601String(),
            'performance' => [
                'tokens_used' => $metrics['tokens'] ?? 0,
                'response_time_ms' => $metrics['response_time_ms'] ?? null,
            ],
            'intelligence' => [
                'intent_detected' => $metrics['intent'] ?? null,
                'mood_detected' => $metrics['mood'] ?? null,
                'episodes_retrieved' => count($metrics['episodes_used'] ?? []),
                'memory_relevant' => !empty($metrics['episodes_used']),
            ],
            'quality' => [
                'reflection_store' => $metrics['reflection']['store'] ?? false,
                'reflection_should_improve' => $metrics['reflection']['should_improve'] ?? false,
                'persona_drift_score' => $metrics['persona_drift_score'] ?? null,
            ],
            'memory' => [
                'compression_applied' => $metrics['compression_applied'] ?? false,
                'episodes_count' => count($metrics['episodes_used'] ?? []),
            ]
        ];
    }
}

