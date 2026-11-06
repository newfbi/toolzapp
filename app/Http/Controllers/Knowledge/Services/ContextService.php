<?php

namespace App\Http\Controllers\Knowledge\Services;

use App\Http\Controllers\Knowledge\Repositories\StreamRepository;
use App\Http\Controllers\Knowledge\Services\KnowledgeConsolidatorService;
use App\Models\Chats;
use Illuminate\Support\Facades\Log;

class ContextService
{
    protected $repository;
    const DEFAULT_CONTEXT_WINDOW_SIZE = 15;
    const CONSOLIDATION_INTERVAL = 15; // Consolida a cada 15 mensagens

    /**
     * Obtém o tamanho da janela de contexto para um agente.
     * Permite configuração dinâmica por agente.
     *
     * @param \App\Models\Agents|null $agent
     * @return int Tamanho da janela
     */
    private function getContextWindowSize($agent = null): int
    {
        if ($agent && isset($agent->context_window_size) && is_numeric($agent->context_window_size)) {
            $size = (int)$agent->context_window_size;
            // Limita entre 5 e 50 para evitar valores extremos
            return max(5, min(50, $size));
        }

        return self::DEFAULT_CONTEXT_WINDOW_SIZE;
    }

    public function __construct(StreamRepository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * Obtém o repositório de streams de memória
     */
    public function getRepository(): StreamRepository
    {
        return $this->repository;
    }

    /**
     * Adiciona uma nova interação ao MemoryStream.
     * Agora armazena o conteúdo real da mensagem para uso direto com Chat Completions.
     *
     * @param Chats $chat
     * @param string $role 'user' ou 'assistant'
     * @param string $content Conteúdo real da mensagem
     * @param array $meta Dados opcionais (intent, entities, etc)
     */
    public function appendInteraction(Chats $chat, string $role, string $content, array $meta = [])
    {
        $session = $this->repository->getOrCreateStream($chat);

        $log = $session->conversation_log ?? ['history' => []];
        if (is_string($log)) {
            $decoded = json_decode($log, true);
            $log = json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? $decoded : ['history' => []];
        }
        $history = isset($log['history']) && is_array($log['history']) ? $log['history'] : [];

        $history[] = [
            'role' => $role,
            'content' => $content,  // Conteúdo real da mensagem
            'timestamp' => now()->toIso8601String(),
            'meta' => array_merge([
                'intent' => $meta['intent'] ?? null,
                'entities' => $meta['entities'] ?? [],
                'tone' => $meta['tone'] ?? 'neutral',
            ], $meta)
        ];

        // Obtém tamanho da janela adaptativa (configurável por agente)
        $agent = $chat->agent ?? null;
        $windowSize = $this->getContextWindowSize($agent);

        // Aplica janela deslizante para manter apenas últimas X mensagens
        $history = array_slice($history, -$windowSize);

        $session->conversation_log = ['history' => $history];

        // Verifica se precisa consolidar conhecimento (a cada 15 pares de mensagens)
        // Conta pares: user + assistant = 1 interação completa
        $totalPairs = (int)floor(count($history) / 2);
        $lastConsolidationPairs = (int)($session->last_consolidation_count ?? 0);
        $pairsSinceLastConsolidation = $totalPairs - $lastConsolidationPairs;

        if ($pairsSinceLastConsolidation >= self::CONSOLIDATION_INTERVAL) {
            $this->triggerConsolidation($session);
            $session->last_consolidation_count = $totalPairs;
        }

        // Verifica se deve fazer análise automática de qualificação de lead
        $this->triggerLeadQualificationAnalysis($chat);

        $this->repository->save($session);
    }

    /**
     * Dispara análise de qualificação de lead se necessário
     */
    private function triggerLeadQualificationAnalysis(Chats $chat)
    {
        try {
            $agent = $chat->agent;
            if (!$agent) {
                return; // Agente não encontrado, não analisa
            }
            // Agora analisa mesmo sem closing definido (análise genérica)

            $leadQualificationService = app(\App\Http\Controllers\Knowledge\Services\LeadQualificationService::class);

            if ($leadQualificationService->shouldAnalyzeAutomatically($chat)) {
                // Executa análise em background (não bloqueia a resposta)
                // Por enquanto executa de forma síncrona, mas rápida
                $leadQualificationService->analyzeLead($chat, $agent, false);
            }
        } catch (\Exception $e) {
            // Não falha a resposta se análise der erro
            Log::warning('Erro ao analisar qualificação de lead: ' . $e->getMessage(), [
                'chat_id' => $chat->uuid
            ]);
        }
    }

    /**
     * Dispara consolidação de conhecimento de forma assíncrona (se possível) ou síncrona
     */
    private function triggerConsolidation($session)
    {
        try {
            // Tenta consolidar em background (não bloqueia a resposta)
            if (class_exists('\Illuminate\Support\Facades\Queue')) {
                // Se tiver Jobs configurado, poderia usar aqui
                // dispatch(new ConsolidateKnowledgeJob($session));
            }

            // Por enquanto, consolida de forma síncrona mas rápida
            $consolidator = app(KnowledgeConsolidatorService::class);
            $consolidator->consolidate($session);
        } catch (\Exception $e) {
            // Não falha a resposta se consolidação der erro
            Log::warning('Erro ao consolidar conhecimento: ' . $e->getMessage(), [
                'chat_id' => $session->chat_id
            ]);
        }
    }

    /**
     * Adiciona múltiplas interações de uma vez (útil para migração)
     */
    public function appendMultipleInteractions(Chats $chat, array $interactions)
    {
        $session = $this->repository->getOrCreateStream($chat);
        $log = $session->conversation_log ?? ['history' => []];
        if (is_string($log)) {
            $decoded = json_decode($log, true);
            $log = json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? $decoded : ['history' => []];
        }
        $history = isset($log['history']) && is_array($log['history']) ? $log['history'] : [];

        foreach ($interactions as $interaction) {
            $history[] = [
                'role' => $interaction['role'],
                'content' => $interaction['content'],
                'timestamp' => $interaction['timestamp'] ?? now()->toIso8601String(),
                'meta' => $interaction['meta'] ?? []
            ];
        }

        // Obtém tamanho da janela adaptativa
        $agent = $chat->agent ?? null;
        $windowSize = $this->getContextWindowSize($agent);

        // Aplica janela deslizante
        $history = array_slice($history, -$windowSize);

        $session->conversation_log = ['history' => $history];
        $this->repository->save($session);
    }

    /**
     * Substitui o conteúdo da última mensagem do assistente no histórico.
     */
    public function replaceLastAssistantMessage(Chats $chat, string $newContent): void
    {
        $session = $this->repository->getOrCreateStream($chat);
        $log = $session->conversation_log ?? null;

        if (empty($log)) {
            return;
        }

        if (is_string($log)) {
            $decoded = json_decode($log, true);
            $log = json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? $decoded : null;
        }

        if (empty($log) || empty($log['history']) || !is_array($log['history'])) {
            return;
        }

        $history = $log['history'];

        for ($i = count($history) - 1; $i >= 0; $i--) {
            if (($history[$i]['role'] ?? null) === 'assistant') {
                $history[$i]['content'] = $newContent;
                $log['history'] = $history;
                $session->conversation_log = $log;
                $this->repository->save($session);
                return;
            }
        }
    }

    /**
     * Constrói array de mensagens formatado para Chat Completions API.
     * Retorna array pronto para uso com OpenAI Chat Completions.
     *
     * @param Chats $chat
     * @param string $systemPrompt Prompt do sistema (personalidade do agente)
     * @param string $userMessage Nova mensagem do usuário
     * @return array Array de mensagens no formato ['role' => '...', 'content' => '...']
     */
    /**
     * Constrói array de mensagens para Chat Completions API
     *
     * @param Chats $chat Chat ativo
     * @param string $systemPrompt System prompt completo
     * @param string $userMessage Mensagem do usuário
     * @param array $images Array de imagens para incluir diretamente (formato vision) - opcional
     * @return array Array de mensagens formatado para API
     */
    public function buildMessagesForChatCompletions(Chats $chat, string $systemPrompt, string $userMessage, array $images = []): array
    {
        $session = $this->repository->getLastStream($chat);

        $messages = [];

        // 1. System Prompt (personalidade e instruções do agente)
        $knowledgeContext = '';
        if ($session && !empty($session->knowledge_base)) {
            // Garante que knowledge_base seja um array (pode vir string/json do banco)
            $kb = $session->knowledge_base;
            if (is_string($kb)) {
                $decoded = json_decode($kb, true);
                $kb = json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? $decoded : [];
            }

            // ✅ NOVA LÓGICA: Não incluir training_condensado aqui
            // (já está no $systemPrompt vindo do ContextController)
            $kbWithoutTraining = $kb;
            unset($kbWithoutTraining['training_condensado']);

            // Formata apenas episódios e dados do usuário (não o training)
            $knowledgeContext = $this->formatKnowledgeForPrompt($kbWithoutTraining);
        }

        $fullSystemPrompt = $systemPrompt . $knowledgeContext;
        $messages[] = [
            'role' => 'system',
            'content' => $fullSystemPrompt
        ];

        // 2. Histórico de conversa (se existir)
        if ($session && !empty($session->conversation_log)) {
            $log = $session->conversation_log;
            if (is_string($log)) {
                $decoded = json_decode($log, true);
                $log = json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? $decoded : [];
            }
            $history = isset($log['history']) && is_array($log['history']) ? $log['history'] : [];

            // Formata histórico para Chat Completions (apenas role e content)
            foreach ($history as $entry) {
                $role = $entry['role'] === 'agent' ? 'assistant' : 'user';
                $messages[] = [
                    'role' => $role,
                    'content' => $entry['content'] ?? ''
                ];
            }
        }

        // 3. Nova mensagem do usuário
        // 🆕 Se houver imagens, usar formato multimodal (vision)
        // NOTA: Formato usado é o padrão OpenAI (formato padrão do sistema)
        // Provedores que suportam vision (Gemini, Claude, etc.) precisam adaptar
        // seus métodos convertMessagesToXXX para lidar com content sendo array
        if (!empty($images)) {
            $content = [];

            // Adicionar texto da mensagem se houver
            if (!empty(trim($userMessage))) {
                $content[] = [
                    'type' => 'text',
                    'text' => $userMessage
                ];
            }

            // Adicionar imagens em formato base64 data URL
            foreach ($images as $imagePath) {
                if (file_exists($imagePath)) {
                    try {
                        $imageData = file_get_contents($imagePath);
                        $base64Image = base64_encode($imageData);
                        $mimeType = mime_content_type($imagePath);
                        $dataUrl = "data:{$mimeType};base64,{$base64Image}";

                        $content[] = [
                            'type' => 'image_url',
                            'image_url' => [
                                'url' => $dataUrl
                            ]
                        ];
                    } catch (\Exception $e) {
                        Log::warning('Erro ao processar imagem para vision', [
                            'image_path' => $imagePath,
                            'error' => $e->getMessage()
                        ]);
                    }
                }
            }

            $messages[] = [
                'role' => 'user',
                'content' => $content
            ];
        } else {
            // Mensagem apenas texto (formato tradicional)
            $messages[] = [
                'role' => 'user',
                'content' => $userMessage
            ];
        }

        return $messages;
    }

    /**
     * Formata a knowledge_base de forma compacta para incluir no system prompt.
     * Economiza tokens ao evitar JSON verboso e filtrar campos desnecessários.
     * 
     * ✅ OTIMIZAÇÃO DE TOKENS:
     * - Remove episodic_memory (já buscada via MemoryFetchingService)
     * - Remove episodic_memory_by_category (duplicação)
     * - Remove training_condensado (já está no system prompt)
     * - Formata dados essenciais em texto compacto
     * 
     * Redução estimada: 60-70% dos tokens da knowledge_base
     */
    private function formatKnowledgeForPrompt(array $knowledge): string
    {
        if (empty($knowledge)) {
            return '';
        }

        // ✅ FILTRAR campos desnecessários ANTES de formatar
        $filtered = $this->filterKnowledgeForPrompt($knowledge);

        if (empty($filtered)) {
            return '';
        }

        // ✅ FORMATAR de forma compacta (texto em vez de JSON)
        return $this->formatCompact($filtered);
    }

    /**
     * Filtra a knowledge_base removendo campos desnecessários para economizar tokens.
     * 
     * @param array $knowledge Knowledge base completa
     * @return array Knowledge base filtrada com apenas dados essenciais
     */
    private function filterKnowledgeForPrompt(array $knowledge): array
    {
        $filtered = [];

        // ❌ NÃO incluir episodic_memory (já é buscada via MemoryFetchingService quando relevante)
        // ❌ NÃO incluir episodic_memory_by_category (duplicação)
        // ❌ NÃO incluir training_condensado (já está no system prompt)

        // ✅ Incluir mood_tracking (apenas essencial)
        if (isset($knowledge['mood_tracking']) && is_array($knowledge['mood_tracking'])) {
            $mood = $knowledge['mood_tracking'];
            $filtered['mood_tracking'] = [
                'current_mood' => $mood['current_mood'] ?? 'neutral',
                'mood_patterns' => $mood['mood_patterns'] ?? []
            ];
            // Não incluir mood_history completo (economiza ~500 tokens)
        }

        // ✅ Incluir qualificacao_lead (apenas essencial)
        if (isset($knowledge['qualificacao_lead']) && is_array($knowledge['qualificacao_lead'])) {
            $lead = $knowledge['qualificacao_lead'];
            $filtered['qualificacao_lead'] = [
                'qualificado' => $lead['qualificado'] ?? false,
                'score' => $lead['score'] ?? 0,
                'analise' => $lead['analise'] ?? ''
            ];
            // Não incluir: criterios_usados, confidence, indicators, data_analise (economiza ~1000 tokens)
        }

        // ✅ Incluir informacoes_pessoais (se existir)
        if (isset($knowledge['informacoes_pessoais']) && is_array($knowledge['informacoes_pessoais'])) {
            $filtered['informacoes_pessoais'] = $knowledge['informacoes_pessoais'];
        }

        // ✅ Incluir outros campos customizados (se houver)
        foreach ($knowledge as $key => $value) {
            // Ignorar campos já processados ou excluídos
            if (in_array($key, [
                'episodic_memory',
                'episodic_memory_by_category',
                'training_condensado',
                'mood_tracking',
                'qualificacao_lead',
                'informacoes_pessoais'
            ])) {
                continue;
            }

            // Incluir outros campos customizados
            $filtered[$key] = $value;
        }

        return $filtered;
    }

    /**
     * Formata knowledge_base filtrada em formato compacto (texto em vez de JSON).
     * 
     * @param array $filtered Knowledge base já filtrada
     * @return string Texto formatado de forma compacta
     */
    private function formatCompact(array $filtered): string
    {
        $formatted = "\n\nContexto sobre este usuário/conversa:\n";

        // Prioriza informações pessoais (especialmente nome)
        if (isset($filtered['informacoes_pessoais']) && is_array($filtered['informacoes_pessoais'])) {
            $info = $filtered['informacoes_pessoais'];
            if (isset($info['nome'])) {
                $formatted .= "Nome do usuário: {$info['nome']}\n";
            }
            foreach ($info as $key => $value) {
                if ($key !== 'nome' && !is_array($value)) {
                    $formatted .= "- {$key}: {$value}\n";
                }
            }
            $formatted .= "\n";
        }

        // Mood tracking (formato compacto)
        if (isset($filtered['mood_tracking']) && is_array($filtered['mood_tracking'])) {
            $mood = $filtered['mood_tracking'];
            $formatted .= "HUMOR:\n";
            $formatted .= "- Atual: " . ($mood['current_mood'] ?? 'neutral') . "\n";

            if (!empty($mood['mood_patterns']) && is_array($mood['mood_patterns'])) {
                $patterns = [];
                foreach ($mood['mood_patterns'] as $emotion => $data) {
                    if (isset($data['percentage']) && $data['percentage'] > 0) {
                        $patterns[] = "{$data['percentage']}% {$emotion}";
                    }
                }
                if (!empty($patterns)) {
                    $formatted .= "- Padrão: " . implode(', ', $patterns) . "\n";
                }
            }
            $formatted .= "\n";
        }

        // Qualificação de lead (formato compacto)
        if (isset($filtered['qualificacao_lead']) && is_array($filtered['qualificacao_lead'])) {
            $lead = $filtered['qualificacao_lead'];
            $formatted .= "QUALIFICAÇÃO:\n";
            $qualificado = $lead['qualificado'] ?? false;
            $score = $lead['score'] ?? 0;
            $formatted .= "- Lead: " . ($qualificado ? "Qualificado" : "Não qualificado") . " ({$score}/100)\n";

            if (!empty($lead['analise'])) {
                // Resumir análise se for muito longa (máximo 200 caracteres)
                $analise = $lead['analise'];
                if (strlen($analise) > 200) {
                    $analise = substr($analise, 0, 197) . '...';
                }
                $formatted .= "- Análise: {$analise}\n";
            }
            $formatted .= "\n";
        }

        // Outros campos customizados (formato simples)
        foreach ($filtered as $key => $value) {
            if (in_array($key, ['informacoes_pessoais', 'mood_tracking', 'qualificacao_lead'])) {
                continue; // Já processados acima
            }

            if (is_array($value)) {
                // Verifica se é lista simples de escalares
                $isList = array_keys($value) === range(0, count($value) - 1);
                $allScalars = true;
                foreach ($value as $v) {
                    if (is_array($v) || is_object($v)) {
                        $allScalars = false;
                        break;
                    }
                }

                if ($isList && $allScalars) {
                    $formatted .= "- {$key}: " . implode(', ', array_map(function ($v) {
                        return (string)$v;
                    }, $value)) . "\n";
                } else {
                    // Para estruturas complexas, usar JSON compacto (último recurso)
                    $formatted .= "- {$key}: " . json_encode($value, JSON_UNESCAPED_UNICODE) . "\n";
                }
            } else {
                $formatted .= "- {$key}: {$value}\n";
            }
        }

        return trim($formatted);
    }

    /**
     * Obtém a sessão de memória do chat.
     *
     * @param Chats $chat
     * @return \App\Models\MemoryStream|null
     */
    public function getSession(Chats $chat)
    {
        return $this->repository->getLastStream($chat);
    }

    /**
     * Monta o contexto MFIR para enviar à IA (método legacy - mantido para compatibilidade)
     * @deprecated Use buildMessagesForChatCompletions() para Chat Completions
     */
    public function buildContextForAI(Chats $chat)
    {
        $session = $this->repository->getLastStream($chat);

        if (!$session) {
            return null;
        }

        $short_term_memory = [];
        if (!empty($session->conversation_log['history'])) {
            $history = $session->conversation_log['history'];
            $agent = $chat->agent ?? null;
            $windowSize = $this->getContextWindowSize($agent);
            $short_term_memory = array_slice($history, -$windowSize);
        }

        $long_term_memory = $session->knowledge_base ?? [];

        return [
            'conversation_id' => $session->conversation_id,
            'knowledge' => $long_term_memory,
            'history' => $short_term_memory
        ];
    }
}
