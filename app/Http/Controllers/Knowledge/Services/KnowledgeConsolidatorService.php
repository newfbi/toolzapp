<?php

namespace App\Http\Controllers\Knowledge\Services;

use App\Http\Controllers\Knowledge\Repositories\StreamRepository;
use App\Http\Controllers\Knowledge\Services\AIProviderFactory;
use App\Models\MemoryStream;
use Illuminate\Support\Facades\Log;

/**
 * Serviço Curador de Conhecimento.
 *
 * Responsabilidade: Analisar uma conversa para extrair, consolidar e
 * aprimorar a base de conhecimento de longo prazo (knowledge_base) do agente.
 * Consolida automaticamente a cada 15 mensagens.
 */
class KnowledgeConsolidatorService
{
    private $repository;

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
     * Analisa e consolida o conhecimento de uma sessão de memória.
     *
     * @param MemoryStream $session O registro de memória da conversa.
     * @return bool True se o conhecimento foi atualizado, false caso contrário.
     */
    public function consolidate(MemoryStream $session): bool
    {
        try {
            // Normaliza knowledge_base e conversation_log
            $currentKnowledge = $session->knowledge_base ?? [];
            if (is_string($currentKnowledge)) {
                $decoded = json_decode($currentKnowledge, true);
                $currentKnowledge = json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? $decoded : [];
            }

            $conversationLog = $session->conversation_log ?? [];
            if (is_string($conversationLog)) {
                $decoded = json_decode($conversationLog, true);
                $conversationLog = json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? $decoded : [];
            }

            // Monta mensagens para Chat Completions
            $messages = $this->buildConsolidationMessages($currentKnowledge, $conversationLog);

            // Chama API do provedor de IA
            $aiProvider = $this->getAIProvider($session->chat->agent);
            $response = $aiProvider->getCompletion($messages, $session->chat->agent, [
                'temperature' => 0.3, // Mais determinístico para análise
                'max_completion_tokens' => 500
            ]);

            $newKnowledgeJson = $response['message'] ?? '{}';

            // Extrai JSON da resposta (pode vir com markdown)
            if (preg_match('/```json\s*(.*?)\s*```/s', $newKnowledgeJson, $matches)) {
                $newKnowledgeJson = $matches[1];
            } elseif (preg_match('/\{.*\}/s', $newKnowledgeJson, $matches)) {
                $newKnowledgeJson = $matches[0];
            }

            $newKnowledge = json_decode(trim($newKnowledgeJson), true);

            if (empty($newKnowledge) || json_last_error() !== JSON_ERROR_NONE) {
                return false; // Nada de novo foi aprendido ou a resposta foi inválida
            }

            // Faz merge dos novos fatos na base de conhecimento existente
            $updatedKnowledge = $this->mergeKnowledge($currentKnowledge, $newKnowledge);

            $session->knowledge_base = $updatedKnowledge;
            $this->repository->save($session);

            return true;
        } catch (\Exception $e) {
            Log::error('Erro na consolidação de conhecimento: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Faz merge inteligente de conhecimento, evitando duplicatas
     */
    private function mergeKnowledge(array $current, array $new): array
    {
        foreach ($new as $key => $value) {
            if (is_array($value) && isset($current[$key]) && is_array($current[$key])) {
                $current[$key] = $this->mergeKnowledge($current[$key], $value);
            } else {
                // Só atualiza se o valor for diferente ou não existir
                if (!isset($current[$key]) || $current[$key] !== $value) {
                    $current[$key] = $value;
                }
            }
        }
        return $current;
    }

    /**
     * Monta array de mensagens para Chat Completions API (formato OpenAI)
     */
    private function buildConsolidationMessages(array $currentKnowledge, array $conversationLog): array
    {
        $systemPrompt = "Você é um analista de conhecimento especializado. Sua tarefa é analisar uma conversa e extrair apenas FATOS IMPORTANTES sobre o usuário, preferências, necessidades ou contexto que sejam relevantes para futuras conversas.\n\n";
        $systemPrompt .= "IMPORTANTE:\n";
        $systemPrompt .= "- Retorne APENAS um objeto JSON válido\n";
        $systemPrompt .= "- Se não houver fatos novos, retorne: {}\n";
        $systemPrompt .= "- Evite informações temporárias ou sem relevância\n";
        $systemPrompt .= "- Estruture o JSON de forma organizada (ex: {\"preferencias\": [], \"informacoes_pessoais\": {}, \"historico\": []})\n";

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt]
        ];

        // Adiciona conhecimento atual se existir
        if (!empty($currentKnowledge)) {
            $kbText = json_encode($currentKnowledge, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            $messages[] = [
                'role' => 'user',
                'content' => "Base de conhecimento atual:\n{$kbText}"
            ];
        }

        // Adiciona histórico recente da conversa
        $historyText = '';
        $history = $conversationLog['history'] ?? [];
        foreach ($history as $entry) {
            $role = $entry['role'] === 'agent' ? 'Assistente' : 'Usuário';
            $historyText .= "[{$role}]: {$entry['content']}\n\n";
        }

        $messages[] = [
            'role' => 'user',
            'content' => "Analise esta conversa e extraia apenas fatos importantes:\n\n{$historyText}\n\nRetorne APENAS o JSON com os novos fatos (ou {} se não houver nada novo):"
        ];

        return $messages;
    }
}
