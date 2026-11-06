<?php

namespace App\Http\Controllers\Knowledge\Services;

/**
 * Post-Processor Service - Pós-Processamento da Resposta.
 *
 * Responsabilidade: Após receber a resposta da IA, realizar:
 * - Sanitização do conteúdo
 * - Aplicação de regras de estilo
 * - Detecção de intenção
 * - Validação de coerência
 * - Gatekeeper interno (verificar se não expôs memória ou regras internas)
 */
class PostProcessorService
{
    /**
     * Processa e sanitiza a resposta do assistente.
     *
     * @param string $response Resposta bruta da IA
     * @param array $options Opções de processamento
     * @return string Resposta processada e sanitizada
     */
    public function process(string $response, array $options = []): string
    {
        $originalLength = strlen($response);

        // 1. Sanitização básica
        $processed = $this->sanitize($response);
        $afterSanitize = strlen($processed);

        // 2. Aplicar regras de estilo
        $processed = $this->applyStyleRules($processed, $options);
        $afterStyle = strlen($processed);

        // 3. Gatekeeper interno ampliado (verificar exposição de memória/regras/URLs/IDs)
        $processed = $this->gatekeeper($processed, $options);
        $afterGatekeeper = strlen($processed);

        // 4. Validação final
        $processedBeforeValidate = $processed;
        $processed = $this->validate($processed);
        $afterValidate = strlen($processed);

        // 🆕 Log se a resposta foi alterada significativamente
        // Verifica se foi apenas truncamento por limite de caracteres (esperado) ou alteração real
        $wasTruncatedByLimit = false;
        if (isset($options['max_characters']) && $options['max_characters'] > 0) {
            $maxChars = (int)$options['max_characters'];
            // Se a redução aconteceu no after_style e o tamanho final está próximo do limite
            // provavelmente foi apenas truncamento por limite (comportamento esperado)
            if ($afterStyle < $afterSanitize && $afterStyle <= $maxChars && abs($afterStyle - $maxChars) < 50) {
                $wasTruncatedByLimit = true;
            }
        }

        // Se foi apenas truncamento por limite, logar como INFO (não WARNING)
        // Alterações no gatekeeper ou validação devem ser warnings
        if ($processed !== $response || $afterValidate !== $originalLength) {
            $logLevel = $wasTruncatedByLimit ? 'info' : 'warning';
            $logMessage = $wasTruncatedByLimit
                ? 'PostProcessorService: Resposta truncada por limite de caracteres (comportamento esperado)'
                : 'PostProcessorService: Resposta foi alterada durante processamento';

            \Illuminate\Support\Facades\Log::$logLevel($logMessage, [
                'original_length' => $originalLength,
                'after_sanitize' => $afterSanitize,
                'after_style' => $afterStyle,
                'after_gatekeeper' => $afterGatekeeper,
                'after_validate' => $afterValidate,
                'original_preview' => substr($response, 0, 100),
                'final_preview' => substr($processed, 0, 100),
                'was_replaced_with_error' => str_contains($processed, 'não consegui processar'),
                'was_truncated_by_limit' => $wasTruncatedByLimit,
                'max_characters' => $options['max_characters'] ?? null
            ]);
        }

        return $processed;
    }

    /**
     * Sanitiza o conteúdo da resposta.
     */
    private function sanitize(string $response): string
    {
        // Remove caracteres de controle (exceto quebras de linha e tabs)
        $response = preg_replace('/[\x00-\x08\x0B-\x0C\x0E-\x1F\x7F]/u', '', $response);

        // Normaliza espaços em branco múltiplos (mas preserva quebras de linha)
        $response = preg_replace('/[ \t]+/', ' ', $response);

        // Garante espaço após pontuação se não houver
        $response = preg_replace('/([.!?:;])([^\s.!?:;])/u', '$1 $2', $response);

        // Garante espaço antes de pontuação de abertura (parênteses, aspas, etc)
        $response = preg_replace('/([^\s])([\(\[\{])/u', '$1 $2', $response);

        // Garante espaço após pontuação de fechamento
        $response = preg_replace('/([\)\]\}])([^\s.!?:;,])/u', '$1 $2', $response);

        // Normaliza quebras de linha múltiplas (mantém no máximo 2)
        $response = preg_replace('/\n{3,}/', "\n\n", $response);

        // Remove espaços no início e fim de cada linha
        $lines = explode("\n", $response);
        $lines = array_map('trim', $lines);
        $response = implode("\n", $lines);

        // Remove espaços no início e fim do texto completo
        $response = trim($response);

        // Remove espaços múltiplos restantes (após normalizações)
        $response = preg_replace('/ {2,}/', ' ', $response);

        return $response;
    }

    /**
     * Aplica regras de estilo.
     */
    private function applyStyleRules(string $response, array $options): string
    {
        // Melhora formatação de listas e estruturas
        $response = $this->formatLists($response);

        // Melhora espaçamento em frases
        $response = $this->improveSpacing($response);

        // Limite de caracteres se especificado
        if (isset($options['max_characters']) && $options['max_characters'] > 0) {
            if (strlen($response) > $options['max_characters']) {
                $response = substr($response, 0, $options['max_characters']);
                // Tenta cortar em uma palavra completa
                $lastSpace = strrpos($response, ' ');
                if ($lastSpace !== false) {
                    $response = substr($response, 0, $lastSpace) . '...';
                }
            }
        }

        // Garante que a resposta não está vazia
        if (empty(trim($response))) {
            $response = 'Desculpe, no momento não estamos atendendo. Tente novamente em outro horário. Agradecemos a compreensão.';
        }

        return $response;
    }

    /**
     * Formata listas e estruturas de texto.
     */
    private function formatLists(string $response): string
    {
        // Formata listas com marcadores (-, *, •)
        $response = preg_replace('/\n\s*[-*•]\s+/', "\n- ", $response);

        // Formata listas numeradas
        $response = preg_replace('/\n\s*(\d+)[.)]\s+/', "\n$1. ", $response);

        // Garante espaço após dois pontos em listas
        $response = preg_replace('/:\s*([^\s])/u', ': $1', $response);

        return $response;
    }

    /**
     * Melhora espaçamento geral do texto.
     */
    private function improveSpacing(string $response): string
    {
        // Remove espaços antes de pontuação final (corrige "texto ." para "texto.")
        $response = preg_replace('/\s+([.!?:;])/u', '$1', $response);

        // Garante espaço após pontuação final se não houver (corrige "texto.Palavra" para "texto. Palavra")
        $response = preg_replace('/([.!?:;])([^\s\n.!?:;])/u', '$1 $2', $response);

        // Garante espaço após vírgula se não houver (corrige "texto,palavra" para "texto, palavra")
        $response = preg_replace('/,([^\s,\n])/u', ', $1', $response);

        // Garante espaço após dois pontos se não houver
        $response = preg_replace('/:([^\s:\n])/u', ': $1', $response);

        // Remove espaços duplos restantes
        $response = preg_replace('/ {2,}/', ' ', $response);

        // Normaliza espaços ao redor de hífens em listas (mantém hífens em palavras compostas)
        $response = preg_replace('/\s+-\s+/', ' - ', $response);

        // Remove espaços no início de linhas após quebras
        $response = preg_replace('/\n\s+/', "\n", $response);

        return $response;
    }

    /**
     * Gatekeeper interno - verifica se não expôs informações internas.
     * Versão ampliada: valida URLs internas, IDs, termos proibidos específicos.
     */
    private function gatekeeper(string $response, array $options = []): string
    {
        $lowerResponse = strtolower($response);

        // ⚠️ INTERCEPTAÇÃO CRÍTICA: Tag técnica nome_do_usuario: NUNCA deve ser exposta ao usuário
        // Remove ou substitui a tag técnica por resposta natural
        if (preg_match('/nome_do_usuario\s*:\s*([^\n]+)/i', $response, $matches)) {
            $name = trim($matches[1]);

            // Valida se é um nome real (sem números, símbolos especiais)
            if (preg_match('/^[a-zA-ZÀ-ÿ\s]{2,50}$/u', $name)) {
                // Substitui a tag técnica por uma resposta natural
                $response = preg_replace(
                    '/nome_do_usuario\s*:\s*' . preg_quote($name, '/') . '[^\n]*/i',
                    "Prazer em conhecê-lo, {$name}! Como posso ajudá-lo?",
                    $response
                );
            } else {
                // Se não for um nome válido, remove apenas a tag técnica
                $response = preg_replace(
                    '/nome_do_usuario\s*:\s*[^\n]*/i',
                    "Prazer em conhecê-lo! Como posso ajudá-lo?",
                    $response
                );
            }

            // Atualiza lowercase response após substituição
            $lowerResponse = strtolower($response);
        }

        // Palavras-chave que indicam exposição de memória/regras internas
        $internalTerms = [
            'memória' => ['memória', 'memory', 'lembrar de', 'armazenado'],
            'regras internas' => ['regra interna', 'prompt', 'system prompt', 'instrução do sistema'],
            'classificação' => ['classificar', 'qualificar', 'lead quente', 'lead morno', 'lead frio'],
            'estrutura técnica' => ['json', 'array', 'objeto', 'episódio', 'episode_id']
        ];

        $issues = [];
        foreach ($internalTerms as $category => $terms) {
            foreach ($terms as $term) {
                if (stripos($lowerResponse, $term) !== false) {
                    $issues[] = $category;
                    break;
                }
            }
        }

        // Valida URLs internas (domínios locais, IPs internos)
        if (preg_match_all('/https?:\/\/(?:localhost|127\.0\.0\.1|192\.168\.|10\.|172\.(?:1[6-9]|2[0-9]|3[01])\.)/i', $response, $matches)) {
            $issues[] = 'url_interna';
            // Remove URLs internas
            foreach ($matches[0] as $url) {
                $response = str_replace($url, '[link interno removido]', $response);
            }
        }

        // Valida IDs técnicos (UUIDs, hashes longos)
        if (preg_match_all('/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/i', $response, $matches)) {
            $issues[] = 'id_exposto';
            // Remove UUIDs expostos
            foreach ($matches[0] as $uuid) {
                $response = str_replace($uuid, '[ID removido]', $response);
            }
        }

        // Valida termos proibidos específicos do domínio (se configurado)
        $forbiddenTerms = $options['forbidden_terms'] ?? [];
        foreach ($forbiddenTerms as $term) {
            if (stripos($lowerResponse, strtolower($term)) !== false) {
                $issues[] = 'termo_proibido';
                // Remove ou substitui termo proibido
                $response = preg_replace('/\b' . preg_quote($term, '/') . '\b/i', '[termo removido]', $response);
            }
        }

        // Se detectou problemas, tenta remover ou substituir
        if (!empty($issues)) {
            // Remove referências explícitas a estruturas internas
            $responseBefore = $response;
            $response = preg_replace('/\b(memória|memory|episódio|episode)\s+(?:do|da|de|interna|interna)\s+(?:sistema|agente|assistente)/i', '', $response);
            $response = preg_replace('/\b(regra|prompt|instrução)\s+(?:interna|do sistema|do agente)/i', '', $response);
            $response = preg_replace('/\b(lead\s+(?:quente|morno|frio|quente|morno|frio))/i', '', $response);

            // Remove referências a estruturas JSON/array
            $response = preg_replace('/\b(json|array|objeto)\s+(?:com|de|do|da)/i', '', $response);

            // Limpa espaços duplos resultantes (preservando quebras de linha)
            $response = preg_replace('/[ \t]{2,}/', ' ', $response);
            $response = trim($response);

            // 🆕 Se após remoções a resposta ficou muito curta (menos de 10 caracteres) ou vazia,
            // manter a resposta original (mais seguro do que retornar mensagem de erro genérica)
            if (strlen(trim($response)) < 10 && strlen(trim($responseBefore)) > 10) {
                // Se a remoção foi muito agressiva, usar resposta original
                $response = $responseBefore;
            }
        }

        return $response;
    }

    /**
     * Valida a resposta final.
     */
    private function validate(string $response): string
    {
        // Garante que não está vazia
        if (empty(trim($response))) {
            \Illuminate\Support\Facades\Log::warning('PostProcessorService: Resposta vazia após processamento', [
                'response_length' => strlen($response),
                'response_preview' => substr($response, 0, 100)
            ]);
            return 'Desculpe, no momento não estamos atendendo. Tente novamente em outro horário. Agradecemos a compreensão.';
        }

        // Garante que não é apenas pontuação/espaços
        if (preg_match('/^[\s\p{P}]+$/u', $response)) {
            \Illuminate\Support\Facades\Log::warning('PostProcessorService: Resposta contém apenas pontuação/espaços', [
                'response_length' => strlen($response),
                'response_preview' => substr($response, 0, 100)
            ]);
            return 'Desculpe, no momento não estamos atendendo. Tente novamente em outro horário. Agradecemos a compreensão.';
        }

        return $response;
    }

    /**
     * Detecta intenção na resposta (útil para análise posterior).
     */
    public function detectIntent(string $response): string
    {
        $lowerResponse = strtolower($response);

        $intentKeywords = [
            'question' => ['como', 'o que', 'qual', 'quando', 'onde', 'por que'],
            'information' => ['informação', 'dados', 'detalhes', 'sobre'],
            'assistance' => ['pode ajudar', 'posso ajudar', 'vou ajudar', 'ajuda'],
            'confirmation' => ['sim', 'correto', 'exato', 'perfeito', 'ok'],
            'apology' => ['desculpe', 'desculpa', 'perdão', 'lamento']
        ];

        foreach ($intentKeywords as $intent => $keywords) {
            foreach ($keywords as $keyword) {
                if (stripos($lowerResponse, $keyword) !== false) {
                    return $intent;
                }
            }
        }

        return 'general';
    }

    /**
     * Verifica se a resposta está coerente (não contradiz memórias).
     *
     * @param string $response
     * @param array $relevantMemory Memórias relevantes
     * @return bool True se coerente
     */
    public function isCoherent(string $response, array $relevantMemory = []): bool
    {
        if (empty($relevantMemory)) {
            return true; // Sem memórias para comparar, assume coerente
        }

        // Verifica contradições básicas (implementação simples)
        // Em produção, poderia usar embeddings para comparação semântica

        $lowerResponse = strtolower($response);

        // Se a resposta menciona algo que contradiz diretamente uma memória
        // (implementação básica - pode ser aprimorada)
        foreach ($relevantMemory as $memory) {
            if (isset($memory['summary'])) {
                // Verifica se há contradição explícita
                // (implementação simplificada)
            }
        }

        return true; // Por enquanto sempre retorna true
    }
}
