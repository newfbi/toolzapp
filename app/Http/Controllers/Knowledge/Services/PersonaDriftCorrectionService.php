<?php

namespace App\Http\Controllers\Knowledge\Services;

use App\Http\Controllers\Knowledge\Services\AIProviderFactory;
use App\Models\Agents;
use Illuminate\Support\Facades\Log;

/**
 * Persona Drift Correction Service - Correção de Deriva de Persona.
 *
 * Responsabilidade: A cada X mensagens, fazer uma micro-chamada à API para
 * validar se a resposta está mantendo consistência com a identidade do agente
 * definida no SystemPromptKernel. Isso evita que o agente "pirue" e perca
 * identidade ao longo dos meses.
 *
 * Técnica inspirada em "Constitutional AI" - validação contínua de aderência.
 */
class PersonaDriftCorrectionService
{
    const DRIFT_CHECK_INTERVAL = 10; // Verifica a cada 10 respostas

    public function __construct() {}

    /**
     * Obtém o provedor de IA correto baseado no agente
     */
    private function getAIProvider($agent)
    {
        return AIProviderFactory::create($agent);
    }

    /**
     * Verifica se precisa fazer correção de persona drift e executa se necessário.
     *
     * @param Agents $agent
     * @param string $lastResponse Última resposta gerada
     * @param int $responseCount Contador de respostas desde última verificação
     * @return array ['needs_correction' => bool, 'corrected_response' => string|null, 'drift_score' => float]
     */
    public function checkAndCorrect(
        Agents $agent,
        string $lastResponse,
        int $responseCount
    ): array {
        // Só verifica se atingiu o intervalo
        if ($responseCount < self::DRIFT_CHECK_INTERVAL) {
            return [
                'needs_correction' => false,
                'corrected_response' => null,
                'drift_score' => 1.0
            ];
        }

        try {
            return $this->validatePersonaConsistency($agent, $lastResponse);
        } catch (\Exception $e) {
            Log::warning('Erro ao verificar persona drift: ' . $e->getMessage(), [
                'agent_id' => $agent->uuid ?? null
            ]);
            // Em caso de erro, assume que está OK
            return [
                'needs_correction' => false,
                'corrected_response' => null,
                'drift_score' => 1.0
            ];
        }
    }

    /**
     * Valida consistência da resposta com a persona do agente.
     */
    private function validatePersonaConsistency(Agents $agent, string $response): array
    {
        $systemPrompt = $this->buildValidationPrompt($agent);

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            [
                'role' => 'user',
                'content' => "RESPOSTA GERADA:\n\n{$response}\n\nAvalie se esta resposta está alinhada com a identidade e personalidade do agente. Retorne APENAS um JSON:\n{\"consistent\": true/false, \"score\": 0.0-1.0, \"corrected\": \"resposta corrigida se necessário\" ou null}"
            ]
        ];

        $aiProvider = $this->getAIProvider($agent);
        $apiResponse = $aiProvider->getCompletion($messages, $agent, [
            'temperature' => 0.2,
            'max_completion_tokens' => 300
        ]);

        $validation = $this->parseValidationResponse($apiResponse['message'] ?? '');

        return [
            'needs_correction' => !($validation['consistent'] ?? true),
            'corrected_response' => $validation['corrected'] ?? null,
            'drift_score' => $validation['score'] ?? 1.0
        ];
    }

    /**
     * Constrói prompt para validação de persona.
     */
    private function buildValidationPrompt(Agents $agent): string
    {
        $name = $agent->name ?? 'Assistente';
        $personality = $agent->personality ?? 'casual';
        if (is_array($personality)) {
            $personality = json_encode($personality, JSON_UNESCAPED_UNICODE);
        }

        $prompt = "Você é um validador de consistência de persona. Sua tarefa é avaliar se uma resposta está alinhada com a identidade e personalidade do agente.\n\n";
        $prompt .= "IDENTIDADE DO AGENTE:\n";
        $prompt .= "- Nome: {$name}\n";
        $prompt .= "- Personalidade: {$personality}\n\n";
        $prompt .= "Avalie:\n";
        $prompt .= "1. A resposta mantém o tom e estilo da personalidade definida?\n";
        $prompt .= "2. A resposta está coerente com a identidade do agente?\n";
        $prompt .= "3. Há alguma contradição ou deriva da persona estabelecida?\n\n";
        $prompt .= "Se a resposta não estiver consistente, forneça uma versão corrigida que mantenha a persona.";

        return $prompt;
    }

    /**
     * Faz parse da resposta de validação.
     */
    private function parseValidationResponse(string $response): array
    {
        // Tenta extrair JSON
        if (preg_match('/```json\s*(.*?)\s*```/s', $response, $matches)) {
            $response = $matches[1];
        } elseif (preg_match('/\{.*\}/s', $response, $matches)) {
            $response = $matches[0];
        }

        $validation = json_decode(trim($response), true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($validation)) {
            // Fallback: assume consistente
            return [
                'consistent' => true,
                'score' => 1.0,
                'corrected' => null
            ];
        }

        return [
            'consistent' => filter_var($validation['consistent'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'score' => (float)($validation['score'] ?? 1.0),
            'corrected' => $validation['corrected'] ?? null
        ];
    }
}
