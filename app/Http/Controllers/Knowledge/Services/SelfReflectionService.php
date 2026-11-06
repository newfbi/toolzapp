<?php

namespace App\Http\Controllers\Knowledge\Services;

use App\Http\Controllers\Knowledge\Services\AIProviderFactory;
use App\Models\Agents;
use Illuminate\Support\Facades\Log;

/**
 * Self-Reflection Service - Auto-Reflexão Pós-Resposta.
 *
 * Responsabilidade: Após gerar uma resposta, fazer uma micro-chamada à API
 * para avaliar se a resposta está alinhada com os objetivos de longo prazo,
 * se não contradiz memórias, e se deve armazenar uma nova memória.
 *
 * Esta técnica melhora a inteligência geral do agente ao validar
 * a qualidade e coerência das respostas antes de finalizar.
 */
class SelfReflectionService
{

    public function __construct() {}

    /**
     * Obtém o provedor de IA correto baseado no agente
     */
    private function getAIProvider($agent)
    {
        return AIProviderFactory::create($agent);
    }

    /**
     * Avalia a resposta gerada e decide se deve armazenar memória.
     *
     * @param Agents $agent
     * @param string $userMessage Mensagem do usuário
     * @param string $assistantResponse Resposta gerada pelo assistente
     * @param array $relevantMemory Memórias relevantes usadas (se houver)
     * @return array ['store' => bool, 'memory' => array|null, 'should_improve' => bool]
     */
    public function reflect(
        Agents $agent,
        string $userMessage,
        string $assistantResponse,
        array $relevantMemory = []
    ): array {
        try {
            $messages = $this->buildReflectionMessages($agent, $userMessage, $assistantResponse, $relevantMemory);

            $aiProvider = $this->getAIProvider($agent);
            $response = $aiProvider->getCompletion($messages, $agent, [
                'temperature' => 0.3, // Mais determinístico para avaliação
                'max_completion_tokens' => 300
            ]);

            $reflectionText = $response['message'] ?? '';

            return $this->parseReflectionResponse($reflectionText);
        } catch (\Exception $e) {
            Log::warning('Erro na auto-reflexão: ' . $e->getMessage());
            // Em caso de erro, decide não armazenar por segurança
            return [
                'store' => false,
                'memory' => null,
                'should_improve' => false
            ];
        }
    }

    /**
     * Constrói mensagens para a chamada de auto-reflexão.
     */
    private function buildReflectionMessages(
        Agents $agent,
        string $userMessage,
        string $assistantResponse,
        array $relevantMemory
    ): array {
        $systemPrompt = "Você é um avaliador interno de qualidade de respostas. Sua tarefa é avaliar se uma resposta gerada está alinhada com os objetivos e se deve ser armazenada como memória.\n\n";
        $systemPrompt .= "Avalie:\n";
        $systemPrompt .= "1. A resposta está alinhada com os objetivos de longo prazo do usuário?\n";
        $systemPrompt .= "2. A resposta contradiz alguma memória existente?\n";
        $systemPrompt .= "3. Esta interação contém informações importantes que devem ser lembradas?\n\n";
        $systemPrompt .= "Retorne APENAS um objeto JSON válido:\n";
        $systemPrompt .= "{\n";
        $systemPrompt .= "  \"store\": true/false,\n";
        $systemPrompt .= "  \"memory\": {\n";
        $systemPrompt .= "    \"summary\": \"resumo curto da interação\",\n";
        $systemPrompt .= "    \"intent\": \"intenção detectada\",\n";
        $systemPrompt .= "    \"tags\": [\"tag1\", \"tag2\"]\n";
        $systemPrompt .= "  } ou null,\n";
        $systemPrompt .= "  \"should_improve\": true/false\n";
        $systemPrompt .= "}\n";

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt]
        ];

        // Adiciona contexto do agente
        $agentContext = "AGENTE: " . ($agent->name ?? 'Assistente') . "\n";
        if (!empty($agent->personality)) {
            $personality = is_array($agent->personality) ? json_encode($agent->personality) : $agent->personality;
            $agentContext .= "Personalidade: {$personality}\n";
        }

        $messages[] = ['role' => 'user', 'content' => $agentContext];

        // Adiciona memórias relevantes se houver
        if (!empty($relevantMemory)) {
            $memoryText = is_array($relevantMemory) ? json_encode($relevantMemory, JSON_UNESCAPED_UNICODE) : $relevantMemory;
            $messages[] = [
                'role' => 'user',
                'content' => "MEMÓRIAS RELEVANTES:\n{$memoryText}\n"
            ];
        }

        // Adiciona interação atual
        $messages[] = [
            'role' => 'user',
            'content' => "INTERAÇÃO ATUAL:\n\nUsuário: {$userMessage}\n\nAssistente: {$assistantResponse}\n\nAvalie esta interação e retorne APENAS o JSON:"
        ];

        return $messages;
    }

    /**
     * Faz parse da resposta de auto-reflexão.
     */
    private function parseReflectionResponse(string $response): array
    {
        // Tenta extrair JSON
        if (preg_match('/```json\s*(.*?)\s*```/s', $response, $matches)) {
            $response = $matches[1];
        } elseif (preg_match('/\{.*\}/s', $response, $matches)) {
            $response = $matches[0];
        }

        $reflection = json_decode(trim($response), true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($reflection)) {
            // Fallback: não armazena se não conseguir parsear
            return [
                'store' => false,
                'memory' => null,
                'should_improve' => false
            ];
        }

        return [
            'store' => filter_var($reflection['store'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'memory' => $reflection['memory'] ?? null,
            'should_improve' => filter_var($reflection['should_improve'] ?? false, FILTER_VALIDATE_BOOLEAN)
        ];
    }
}
