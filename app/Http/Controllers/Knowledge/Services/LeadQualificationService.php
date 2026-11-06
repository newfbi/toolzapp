<?php

namespace App\Http\Controllers\Knowledge\Services;

use App\Http\Controllers\Knowledge\Repositories\StreamRepository;
use App\Http\Controllers\Knowledge\Services\AIProviderFactory;
use App\Models\Chats;
use App\Models\Agents;
use Illuminate\Support\Facades\Log;

/**
 * Serviço de Análise de Qualificação de Leads.
 *
 * Responsabilidade: Analisar o contexto da conversa do usuário e identificar
 * se o lead é qualificado baseado nos critérios de closing definidos no agente.
 * Armazena a análise na knowledge_base e atualiza o status de conversão do chat.
 */
class LeadQualificationService
{
    private $repository;
    private const DEFAULT_QUALIFICATION_INTERVAL = 6; // Analisa a cada 6 mensagens

    public function __construct(StreamRepository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * Obtém o provedor de IA correto baseado no agente
     */
    private function getAIProvider($agent)
    {
        return AIProviderFactory::create($agent);
    }

    /**
     * Analisa se o lead é qualificado baseado nos critérios de closing.
     *
     * @param Chats $chat O chat a ser analisado
     * @param Agents $agent O agente com os critérios de closing
     * @param bool $forceAnalysis Força a análise mesmo que já tenha sido feita recentemente
     * @return array ['qualified' => bool, 'score' => int, 'reasons' => array, 'analysis' => string]
     */
    public function analyzeLead(Chats $chat, Agents $agent, bool $forceAnalysis = false): array
    {
        try {
            $session = $this->repository->getOrCreateStream($chat);

            // Verifica se já foi analisado recentemente (a menos que seja forçado)
            if (!$forceAnalysis) {
                $lastAnalysis = $session->last_qualification_analysis ?? null;
                if ($lastAnalysis) {
                    $lastAnalysisTime = \Carbon\Carbon::parse($lastAnalysis);
                    // Se analisou há menos de 5 minutos e não há mudanças significativas, retorna cache
                    if ($lastAnalysisTime->diffInMinutes(now()) < 5) {
                        $cachedAnalysis = $session->lead_qualification ?? null;
                        // O cast do modelo já retorna como array, mas verifica compatibilidade com dados antigos
                        if ($cachedAnalysis) {
                            if (is_array($cachedAnalysis)) {
                                return $cachedAnalysis;
                            } elseif (is_string($cachedAnalysis)) {
                                // Compatibilidade com dados antigos salvos como JSON string
                                $decoded = json_decode($cachedAnalysis, true);
                                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                                    return $decoded;
                                }
                            }
                        }
                    }
                }
            }

            // Normaliza dados da sessão
            $conversationLog = $session->conversation_log ?? [];
            if (is_string($conversationLog)) {
                $decoded = json_decode($conversationLog, true);
                $conversationLog = json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? $decoded : [];
            }

            $knowledge = $session->knowledge_base ?? [];
            if (is_string($knowledge)) {
                $decoded = json_decode($knowledge, true);
                $knowledge = json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? $decoded : [];
            }

            // Monta mensagens para análise
            // Se não tem closing definido, usa análise genérica baseada no contexto
            $hasClosingCriteria = !empty($agent->closing);
            $messages = $hasClosingCriteria
                ? $this->buildQualificationMessages($agent, $conversationLog, $knowledge)
                : $this->buildGenericQualificationMessages($agent, $conversationLog, $knowledge);

            // Chama API do provedor de IA para análise
            $aiProvider = $this->getAIProvider($agent);
            $response = $aiProvider->getCompletion($messages, $agent, [
                'temperature' => 0.1, // Mais determinístico para análise objetiva e consistente
                'max_completion_tokens' => 1200 // Aumentado para permitir análises mais detalhadas
            ]);

            $analysisText = $response['message'] ?? '';

            // Extrai JSON da resposta (pode vir com markdown)
            $analysisData = $this->extractQualificationAnalysis($analysisText);

            // Processa e valida a análise
            $result = $this->processQualificationResult($analysisData);

            // Salva análise na knowledge_base
            $hasClosingCriteria = !empty($agent->closing);
            $knowledge['qualificacao_lead'] = [
                'qualificado' => $result['qualified'],
                'score' => $result['score'],
                'motivos' => $result['reasons'],
                'analise' => $result['analysis'],
                'data_analise' => now()->toIso8601String(),
                'tipo_analise' => $hasClosingCriteria ? 'com_criterios' : 'generica',
                'criterios_usados' => $hasClosingCriteria ? $agent->closing : 'Análise genérica baseada no contexto da conversa',
                'confidence' => $result['confidence'] ?? null,
                'indicators' => $result['indicators'] ?? [],
                'versao_analise' => '2.0' // Versão do formato de análise
            ];

            $session->knowledge_base = $knowledge;
            // Salva como array diretamente (o cast do modelo cuida da conversão para JSON no banco)
            $session->lead_qualification = $result;
            $session->last_qualification_analysis = now()->toIso8601String();

            // Salva também o número de mensagens no momento desta análise
            $conversationLog = $session->conversation_log ?? [];
            if (is_string($conversationLog)) {
                $decoded = json_decode($conversationLog, true);
                $conversationLog = json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? $decoded : [];
            }
            $history = $conversationLog['history'] ?? [];
            $session->last_qualification_count = count($history);

            $this->repository->save($session);

            // Atualiza campo conversion do chat se lead for qualificado OU score >= 80
            // Verifica se já está convertido (compatível com string 'true' ou boolean true)
            $isAlreadyConverted = $chat->conversion === true || $chat->conversion === 'true' || $chat->conversion === 1;
            if (($result['qualified'] || ($result['score'] >= 80)) && !$isAlreadyConverted) {
                $chat->conversion = 'true';
                $chat->save();
            }

            return $result;
        } catch (\Exception $e) {
            Log::error('Erro ao analisar qualificação de lead: ' . $e->getMessage(), [
                'chat_id' => $chat->uuid,
                'agent_id' => $agent->uuid
            ]);

            return [
                'qualified' => false,
                'score' => 0,
                'reasons' => [],
                'analysis' => 'Erro ao processar análise de qualificação.',
                'message' => 'Erro técnico ao analisar lead.'
            ];
        }
    }

    /**
     * Constrói as mensagens para análise de qualificação via Chat Completions
     */
    private function buildQualificationMessages(Agents $agent, array $conversationLog, array $knowledge): array
    {
        $systemPrompt = "Você é um analista sênior de inteligência comercial e qualificação de leads (BANT & SPIN Selling). Sua tarefa é analisar o histórico de conversa com o cliente e avaliar seu nível de maturidade, intenção de compra e enquadramento nos critérios do negócio.\n\n";
        $systemPrompt .= "INSTRUÇÕES IMPORTANTES:\n";
        $systemPrompt .= "- Analise o contexto completo da conversa com base nos critérios de qualificação (Closing) do agente\n";
        $systemPrompt .= "- Retorne APENAS um objeto JSON válido no seguinte formato rigoroso:\n";
        $systemPrompt .= "{\n";
        $systemPrompt .= "  \"qualified\": true/false,\n";
        $systemPrompt .= "  \"score\": 0-100,\n";
        $systemPrompt .= "  \"etapa\": \"descoberta\" | \"interesse\" | \"em_negociacao\" | \"pronto_para_fechar\" | \"desqualificado\",\n";
        $systemPrompt .= "  \"urgencia\": \"baixa\" | \"media\" | \"alta\" | \"imediata\",\n";
        $systemPrompt .= "  \"sinais_compra\": [\"sinal 1\", \"sinal 2\"],\n";
        $systemPrompt .= "  \"objecoes\": [\"objecao 1\", \"objecao 2\"],\n";
        $systemPrompt .= "  \"reasons\": [\"motivo principal 1\", \"motivo 2\"],\n";
        $systemPrompt .= "  \"analysis\": \"diagnóstico executivo claro e conciso sobre a situação do lead\",\n";
        $systemPrompt .= "  \"bant\": {\n";
        $systemPrompt .= "    \"budget\": \"adequado\" | \"baixo\" | \"nao_identificado\",\n";
        $systemPrompt .= "    \"authority\": \"decisor\" | \"influenciador\" | \"nao_identificado\",\n";
        $systemPrompt .= "    \"need\": \"clara\" | \"moderada\" | \"baixa\",\n";
        $systemPrompt .= "    \"timeline\": \"imediato\" | \"curto_prazo\" | \"longo_prazo\" | \"nao_identificado\"\n";
        $systemPrompt .= "  },\n";
        $systemPrompt .= "  \"criteria_met\": { \"criterio1\": true/false }\n";
        $systemPrompt .= "}\n";
        $systemPrompt .= "- Seja rigoroso e baseie-se estritamente nos fatos expressos na conversa.\n";

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt]
        ];

        // Adiciona critérios de closing do agente
        $closingStr = is_array($agent->closing) ? json_encode($agent->closing, JSON_UNESCAPED_UNICODE) : ($agent->closing ?? '');
        $messages[] = [
            'role' => 'user',
            'content' => "CRITÉRIOS DE QUALIFICAÇÃO (CLOSING) PARA ESTE AGENTE:\n\n" . $closingStr . "\n\n"
        ];

        // Adiciona conhecimento consolidado se existir (filtrado para relevância)
        $relevantKnowledge = $this->extractRelevantKnowledge($knowledge);
        if (!empty($relevantKnowledge)) {
            $knowledgeText = json_encode($relevantKnowledge, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            $messages[] = [
                'role' => 'user',
                'content' => "CONHECIMENTO CONSOLIDADO SOBRE ESTE USUÁRIO:\n\n" . $knowledgeText . "\n\n"
            ];
        }

        // Adiciona histórico da conversa
        $historyText = "HISTÓRICO COMPLETO DA CONVERSA:\n\n";
        $history = $conversationLog['history'] ?? [];
        foreach ($history as $entry) {
            $role = $entry['role'] === 'agent' || $entry['role'] === 'assistant' ? 'Agente' : 'Usuário';
            $timestamp = $entry['timestamp'] ?? '';
            $content = $entry['content'] ?? '';
            $historyText .= "[{$role}] ({$timestamp}): {$content}\n\n";
        }

        $messages[] = [
            'role' => 'user',
            'content' => $historyText . "\n\nAnalise esta conversa e determine se o lead é QUALIFICADO baseado nos critérios fornecidos. Retorne APENAS o JSON sem markdown ou explicações adicionais:"
        ];

        return $messages;
    }

    /**
     * Constrói mensagens para análise genérica de qualificação (sem critérios específicos de closing)
     * Baseia-se no contexto da conversa, conhecimento consolidado e propósito do agente
     */
    private function buildGenericQualificationMessages(Agents $agent, array $conversationLog, array $knowledge): array
    {
        $systemPrompt = "Você é um analista especializado em avaliar a qualidade e relevância de interações e conversas. Sua tarefa é analisar uma conversa e determinar o nível de engajamento, interesse e potencial do usuário baseado no contexto e conteúdo da discussão.\n\n";
        $systemPrompt .= "INSTRUÇÕES IMPORTANTES:\n";
        $systemPrompt .= "- Analise o contexto completo da conversa de forma objetiva\n";
        $systemPrompt .= "- Considere o propósito e contexto do agente/negócio\n";
        $systemPrompt .= "- Avalie sinais de interesse genuíno, engajamento, necessidade identificada\n";
        $systemPrompt .= "- Identifique potenciais oportunidades ou valor na interação\n";
        $systemPrompt .= "- Retorne APENAS um objeto JSON válido no seguinte formato:\n";
        $systemPrompt .= "{\n";
        $systemPrompt .= "  \"qualified\": true/false,\n";
        $systemPrompt .= "  \"score\": 0-100 (pontuação de 0 a 100),\n";
        $systemPrompt .= "  \"reasons\": [\"motivo 1\", \"motivo 2\", ...],\n";
        $systemPrompt .= "  \"analysis\": \"análise detalhada em texto livre\",\n";
        $systemPrompt .= "  \"indicators\": { \"engajamento\": \"alto/médio/baixo\", \"interesse\": \"sim/não/incerto\", \"potencial\": \"alto/médio/baixo\" }\n";
        $systemPrompt .= "}\n";
        $systemPrompt .= "- Seja objetivo e baseie-se apenas nos fatos da conversa\n";
        $systemPrompt .= "- Não invente informações que não foram mencionadas\n";

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt]
        ];

        // Adiciona contexto do agente (personalidade, training, propósito)
        $agentContext = $this->buildAgentContext($agent);
        if (!empty($agentContext)) {
            $messages[] = [
                'role' => 'user',
                'content' => "CONTEXTO DO AGENTE:\n\n" . $agentContext . "\n"
            ];
        }

        // Adiciona conhecimento consolidado se existir (filtrado para relevância)
        $relevantKnowledge = $this->extractRelevantKnowledge($knowledge);
        if (!empty($relevantKnowledge)) {
            $knowledgeText = json_encode($relevantKnowledge, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            $messages[] = [
                'role' => 'user',
                'content' => "CONHECIMENTO CONSOLIDADO SOBRE ESTE USUÁRIO:\n\n" . $knowledgeText . "\n\n"
            ];
        }

        // Adiciona histórico da conversa
        $historyText = "HISTÓRICO COMPLETO DA CONVERSA:\n\n";
        $history = $conversationLog['history'] ?? [];
        foreach ($history as $entry) {
            $role = $entry['role'] === 'agent' || $entry['role'] === 'assistant' ? 'Agente' : 'Usuário';
            $timestamp = $entry['timestamp'] ?? '';
            $content = $entry['content'] ?? '';
            $historyText .= "[{$role}] ({$timestamp}): {$content}\n\n";
        }

        $messages[] = [
            'role' => 'user',
            'content' => $historyText . "\n\nAnalise esta conversa de forma objetiva e determine o nível de qualificação/relevância do usuário baseado em:\n" .
                "- Engajamento e interesse demonstrado\n" .
                "- Clareza nas necessidades ou intenções\n" .
                "- Potencial de valor ou oportunidade\n" .
                "- Qualidade e profundidade da interação\n\n" .
                "Retorne APENAS o JSON sem markdown ou explicações adicionais:"
        ];

        return $messages;
    }

    /**
     * Extrai análise de qualificação da resposta da IA
     */
    private function extractQualificationAnalysis(string $response): array
    {
        // Tenta extrair JSON de markdown
        if (preg_match('/```json\s*(.*?)\s*```/s', $response, $matches)) {
            $response = $matches[1];
        } elseif (preg_match('/\{.*\}/s', $response, $matches)) {
            $response = $matches[0];
        }

        $analysis = json_decode(trim($response), true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($analysis)) {
            // Se não conseguir extrair JSON, tenta fazer uma análise básica baseada em palavras-chave
            return $this->fallbackAnalysis($response);
        }

        return $analysis;
    }

    /**
     * Análise fallback caso o JSON não seja extraído corretamente
     */
    private function fallbackAnalysis(string $response): array
    {
        $score = 0;
        $matchedReasons = [];

        // Busca palavras-chave que indicam qualificação
        $positiveKeywords = [
            'qualificado' => 'Lead demonstra qualificação',
            'interessado' => 'Lead demonstra interesse',
            'orçamento' => 'Lead mencionou orçamento',
            'autoridade' => 'Lead possui autoridade para decisão',
            'urgência' => 'Lead demonstra urgência',
            'necessidade' => 'Lead tem necessidade identificada'
        ];
        $negativeKeywords = [
            'não interessado' => 'Lead não demonstra interesse',
            'sem interesse' => 'Lead sem interesse',
            'sem orçamento' => 'Lead sem orçamento disponível',
            'apenas curiosidade' => 'Apenas curiosidade sem intenção de compra'
        ];

        $lowerResponse = strtolower($response);

        foreach ($positiveKeywords as $keyword => $reason) {
            if (stripos($lowerResponse, $keyword) !== false) {
                $score += 15;
                $matchedReasons[] = $reason;
            }
        }

        foreach ($negativeKeywords as $keyword => $reason) {
            if (stripos($lowerResponse, $keyword) !== false) {
                $score -= 20;
                $matchedReasons[] = $reason;
            }
        }

        // Se não encontrou nenhuma palavra-chave, adiciona motivo padrão
        if (empty($matchedReasons)) {
            $matchedReasons[] = 'Análise baseada em palavras-chave (análise automática falhou)';
        }

        return [
            'qualified' => $score >= 60,
            'score' => max(0, min(100, $score)),
            'reasons' => $matchedReasons,
            'analysis' => $response
        ];
    }

    /**
     * Processa e valida o resultado da qualificação
     */
    private function processQualificationResult(array $analysisData): array
    {
        // Garante que todos os campos obrigatórios existam
        $result = [
            'qualified' => filter_var($analysisData['qualified'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'score' => (int)($analysisData['score'] ?? 0),
            'etapa' => $analysisData['etapa'] ?? 'descoberta',
            'urgencia' => $analysisData['urgencia'] ?? 'media',
            'sinais_compra' => is_array($analysisData['sinais_compra'] ?? null) ? $analysisData['sinais_compra'] : [],
            'objecoes' => is_array($analysisData['objecoes'] ?? null) ? $analysisData['objecoes'] : [],
            'bant' => is_array($analysisData['bant'] ?? null) ? $analysisData['bant'] : [],
            'reasons' => is_array($analysisData['reasons'] ?? null) ? $analysisData['reasons'] : [],
            'analysis' => $analysisData['analysis'] ?? 'Análise não disponível.',
            'criteria_met' => is_array($analysisData['criteria_met'] ?? null) ? $analysisData['criteria_met'] : [],
            'indicators' => is_array($analysisData['indicators'] ?? null) ? $analysisData['indicators'] : [],
            'confidence' => isset($analysisData['confidence']) ? (float)$analysisData['confidence'] : null
        ];

        // Normaliza score (0-100)
        $result['score'] = max(0, min(100, $result['score']));

        // Normaliza confidence se fornecido (0-1)
        if ($result['confidence'] !== null) {
            $result['confidence'] = max(0, min(1, $result['confidence']));
        }

        // Se o score for alto mas não marcado como qualificado, ajusta automaticamente
        if ($result['score'] >= 70 && !$result['qualified']) {
            $result['qualified'] = true;
            if (!in_array('Pontuação alta indica qualificação', $result['reasons'])) {
                $result['reasons'][] = 'Pontuação alta indica qualificação';
            }
        }

        // Se qualificado mas score baixo, ajusta
        if ($result['qualified'] && $result['score'] < 50) {
            $result['score'] = 50; // Mínimo para qualificado
        }

        // Validação adicional baseada em indicadores se disponíveis
        if (!empty($result['indicators'])) {
            $this->validateWithIndicators($result);
        }

        return $result;
    }

    /**
     * Valida resultado usando indicadores adicionais
     */
    private function validateWithIndicators(array &$result): void
    {
        $indicators = $result['indicators'];
        $positiveCount = 0;
        $totalIndicators = 0;

        // Conta indicadores positivos
        foreach (['engajamento', 'interesse', 'potencial', 'necessidade'] as $key) {
            if (isset($indicators[$key])) {
                $totalIndicators++;
                $value = strtolower($indicators[$key]);
                if (in_array($value, ['alto', 'sim', 'claro', 'forte', 'high', 'yes', 'strong'])) {
                    $positiveCount++;
                }
            }
        }

        // Se maioria dos indicadores são positivos mas não está qualificado, reconsidera
        if ($totalIndicators > 0 && ($positiveCount / $totalIndicators) >= 0.6 && !$result['qualified']) {
            $result['qualified'] = true;
            $result['reasons'][] = 'Indicadores positivos sugerem qualificação';
            if ($result['score'] < 60) {
                $result['score'] = 60;
            }
        }
    }

    /**
     * Verifica se deve fazer análise automática baseado no intervalo configurado
     */
    public function shouldAnalyzeAutomatically(Chats $chat): bool
    {
        $interval = $this->resolveQualificationInterval($chat);

        $session = $this->repository->getOrCreateStream($chat);

        // Conta mensagens do histórico
        $conversationLog = $session->conversation_log ?? [];
        if (is_string($conversationLog)) {
            $decoded = json_decode($conversationLog, true);
            $conversationLog = json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? $decoded : [];
        }

        $history = $conversationLog['history'] ?? [];
        $totalMessages = count($history);

        // Se não tem mensagens suficientes, não analisa
        if ($totalMessages < $interval) {
            return false;
        }

        // Verifica se já analisou antes
        $lastAnalysis = $session->last_qualification_analysis ?? null;

        // Se nunca analisou, analisa agora que atingiu o mínimo
        if (!$lastAnalysis) {
            return true;
        }

        // Se já analisou, verifica quantas mensagens foram adicionadas desde a última análise
        $lastAnalysisTime = \Carbon\Carbon::parse($lastAnalysis);

        // Verifica se passou tempo suficiente (mínimo 3 minutos entre análises)
        $minutesSinceLastAnalysis = $lastAnalysisTime->diffInMinutes(now());
        if ($minutesSinceLastAnalysis < 3) {
            return false;
        }

        // Obtém o número de mensagens que tinha quando analisou pela última vez
        $lastQualificationCount = $session->last_qualification_count ?? 0;

        // Calcula quantas mensagens foram adicionadas desde a última análise
        $messagesSinceLastAnalysis = $totalMessages - $lastQualificationCount;

        // Analisa se passou pelo menos QUALIFICATION_INTERVAL mensagens desde a última análise
        return $messagesSinceLastAnalysis >= $interval;
    }

    /**
     * Obtém o intervalo mínimo de mensagens antes de uma nova análise automática.
     */
    private function resolveQualificationInterval(Chats $chat): int
    {
        $agent = $chat->agent ?? null;

        if (!$agent) {
            return self::DEFAULT_QUALIFICATION_INTERVAL;
        }

        $interval = $agent->lead_interval ?? null;
        $interval = is_numeric($interval) ? (int) $interval : null;

        if ($interval === null || $interval < 1) {
            return self::DEFAULT_QUALIFICATION_INTERVAL;
        }

        return $interval;
    }

    /**
     * Constrói contexto completo do agente para análise
     */
    private function buildAgentContext(Agents $agent): string
    {
        $context = [];

        // Nome e tipo do agente
        if (!empty($agent->name)) {
            $context[] = "Nome: " . $agent->name;
        }

        if (!empty($agent->agent_type)) {
            $context[] = "Tipo: " . $agent->agent_type;
        }

        // Expertise/Propósito
        if (!empty($agent->expertise)) {
            $expertiseMap = [
                'suporte' => 'Suporte ao Cliente',
                'vendas' => 'Vendas',
                'assistente' => 'Assistente Virtual',
                'geral' => 'Atendimento Geral'
            ];
            $expertise = $expertiseMap[$agent->expertise] ?? $agent->expertise;
            $context[] = "Área de Atuação: " . $expertise;
        }

        // Personalidade
        if (!empty($agent->personality)) {
            $personalityMap = [
                'formal' => 'Formal e Profissional',
                'casual' => 'Casual e Descontraído',
                'friendly' => 'Amigável e Acolhedor',
                'professional' => 'Profissional',
                'tecnica' => 'Técnica e Específica'
            ];
            $personality = $personalityMap[$agent->personality] ?? $agent->personality;
            $context[] = "Personalidade: " . $personality;
        }

        // Training/Instruções (contexto do negócio)
        if (!empty($agent->training)) {
            $training = is_string($agent->training) ? $agent->training : json_encode($agent->training, JSON_UNESCAPED_UNICODE);
            $context[] = "Treinamento/Contexto do Negócio:\n" . $training;
        }

        // Instructions adicionais
        if (!empty($agent->instructions)) {
            $instructions = is_string($agent->instructions) ? $agent->instructions : json_encode($agent->instructions, JSON_UNESCAPED_UNICODE);
            $context[] = "Instruções Específicas:\n" . $instructions;
        }

        return implode("\n\n", $context);
    }

    /**
     * Extrai conhecimento relevante para qualificação (filtra dados desnecessários)
     */
    private function extractRelevantKnowledge(array $knowledge): array
    {
        $relevant = [];

        // Campos relevantes para qualificação
        $relevantKeys = [
            'qualificacao_lead',
            'informacoes_usuario',
            'necessidades',
            'interesses',
            'objeções',
            'intencoes',
            'preferencias',
            'dados_contato',
            'historico_interacoes'
        ];

        foreach ($relevantKeys as $key) {
            if (isset($knowledge[$key])) {
                $relevant[$key] = $knowledge[$key];
            }
        }

        // Se não encontrou campos específicos, retorna conhecimento consolidado geral
        if (empty($relevant) && !empty($knowledge)) {
            // Limita tamanho para não sobrecarregar o prompt
            $relevant = array_slice($knowledge, 0, 10, true);
        }

        return $relevant;
    }
}
