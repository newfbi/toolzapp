<?php

namespace App\Http\Controllers\Knowledge\Services;

use App\Models\Chats;

/**
 * Memory Fetching Service - Recuperação Inteligente de Memória.
 *
 * Responsabilidade: Antes da chamada à API, extrair intenção do usuário,
 * buscar episódios relevantes na memória de longo prazo usando similaridade,
 * e sintetizar em um parágrafo contextual para incluir no prompt.
 *
 * Fluxo:
 * 1. Extrair o que o usuário disse → gerar embeddings (similaridade simples)
 * 2. Consultar memória de longo prazo com KNN (top 3 episódios)
 * 3. Filtrar por similaridade > 0.45
 * 4. Sintetizar num parágrafo curto
 */
class MemoryFetchingService
{
    private $episodicMemoryService;

    public function __construct(EpisodicMemoryService $episodicMemoryService)
    {
        $this->episodicMemoryService = $episodicMemoryService;
    }

    /**
     * Busca e sintetiza memórias relevantes para uma mensagem do usuário.
     *
     * @param Chats $chat
     * @param string $userMessage Mensagem atual do usuário
     * @return string Parágrafo sintetizado com contexto de memória (vazio se não houver memórias relevantes)
     */
    public function fetchRelevantMemory(Chats $chat, string $userMessage): string
    {
        // 1. Busca episódios relevantes
        $relevantEpisodes = $this->episodicMemoryService->findRelevantEpisodes($chat, $userMessage, 3);

        if (empty($relevantEpisodes)) {
            return '';
        }

        // 2. Sintetiza em parágrafo contextual
        return $this->synthesizeMemoryContext($relevantEpisodes, $userMessage);
    }

    /**
     * Sintetiza episódios relevantes em um parágrafo contextual.
     *
     * @param array $episodes Episódios relevantes
     * @param string $userMessage Mensagem atual do usuário
     * @return string Parágrafo sintetizado
     */
    private function synthesizeMemoryContext(array $episodes, string $userMessage): string
    {
        if (empty($episodes)) {
            return '';
        }

        $context = "CONTEXT FROM MEMORY:\n\n";

        // Agrupa informações por categoria
        $intents = [];
        $emotions = [];
        $summaries = [];
        $tags = [];

        foreach ($episodes as $episode) {
            if (!empty($episode['intent'])) {
                $intents[] = $episode['intent'];
            }
            if (!empty($episode['emotion'])) {
                $emotions[] = $episode['emotion'];
            }
            if (!empty($episode['summary'])) {
                $summaries[] = $episode['summary'];
            }
            if (!empty($episode['tags']) && is_array($episode['tags'])) {
                $tags = array_merge($tags, $episode['tags']);
            }
        }

        // Constrói contexto sintetizado
        $contextParts = [];

        // Intenções anteriores
        if (!empty($intents)) {
            $uniqueIntents = array_unique($intents);
            $contextParts[] = "O usuário anteriormente demonstrou interesse em: " . implode(', ', $uniqueIntents);
        }

        // Emoções detectadas
        if (!empty($emotions)) {
            $emotionCounts = array_count_values($emotions);
            arsort($emotionCounts);
            $dominantEmotion = array_key_first($emotionCounts);
            if ($dominantEmotion !== 'neutral') {
                $contextParts[] = "Estado emocional predominante: {$dominantEmotion}";
            }
        }

        // Resumos relevantes
        if (!empty($summaries)) {
            $contextParts[] = "Contextos anteriores relevantes: " . implode('; ', array_slice($summaries, 0, 2));
        }

        // Tags/tópicos
        if (!empty($tags)) {
            $uniqueTags = array_unique($tags);
            $contextParts[] = "Tópicos de interesse: " . implode(', ', array_slice($uniqueTags, 0, 5));
        }

        // Adiciona conselho do assistente se relevante
        foreach ($episodes as $episode) {
            if (!empty($episode['assistant_advice']) && count($contextParts) < 4) {
                $advice = substr($episode['assistant_advice'], 0, 100);
                if (strlen($episode['assistant_advice']) > 100) {
                    $advice .= '...';
                }
                $contextParts[] = "Conselho anterior relevante: {$advice}";
                break; // Apenas o primeiro conselho relevante
            }
        }

        if (empty($contextParts)) {
            return '';
        }

        $context .= implode("\n", $contextParts);
        $context .= "\n\nSeja consistente com o histórico e preferências do usuário.";

        return $context;
    }

    /**
     * Extrai intenção da mensagem do usuário para busca de memória.
     *
     * @param string $userMessage
     * @return array ['intent' => string, 'keywords' => array]
     */
    public function extractIntentForMemory(string $userMessage): array
    {
        $messageLower = strtolower($userMessage);

        // Extrai palavras-chave importantes
        $stopWords = ['o', 'a', 'os', 'as', 'um', 'uma', 'de', 'do', 'da', 'dos', 'das', 'em', 'no', 'na', 'nos', 'nas', 'para', 'com', 'por', 'que', 'é', 'são', 'foi', 'ser', 'ter', 'tem', 'está', 'estão', 'eu', 'você', 'ele', 'ela', 'nós', 'eles', 'elas'];

        $words = explode(' ', $messageLower);
        $keywords = array_filter($words, function ($word) use ($stopWords) {
            $word = trim($word);
            return strlen($word) > 2 && !in_array($word, $stopWords);
        });

        // Detecta intenção básica
        $intent = 'general';
        $intentKeywords = [
            'question' => ['como', 'o que', 'qual', 'quando', 'onde', 'por que', 'porque'],
            'request' => ['preciso', 'quero', 'gostaria', 'pode', 'poderia', 'ajuda'],
            'information' => ['informação', 'dados', 'detalhes', 'sobre'],
            'problem' => ['problema', 'erro', 'não funciona', 'bug', 'falha']
        ];

        foreach ($intentKeywords as $intentType => $keywords) {
            foreach ($keywords as $keyword) {
                if (stripos($messageLower, $keyword) !== false) {
                    $intent = $intentType;
                    break 2;
                }
            }
        }

        return [
            'intent' => $intent,
            'keywords' => array_values($keywords)
        ];
    }

    /**
     * Verifica se há memórias relevantes para a mensagem atual.
     *
     * @param Chats $chat
     * @param string $userMessage
     * @return bool True se houver memórias relevantes
     */
    public function hasRelevantMemory(Chats $chat, string $userMessage): bool
    {
        $relevantEpisodes = $this->episodicMemoryService->findRelevantEpisodes($chat, $userMessage, 1);
        return !empty($relevantEpisodes);
    }
}
