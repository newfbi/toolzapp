<?php

namespace App\Http\Controllers\Knowledge\Services;

use App\Models\Chats;
use Illuminate\Support\Facades\Log;

/**
 * Multimodal Search Service - Busca Inteligente em Conteúdo Multimodal
 *
 * Responsabilidade: Buscar e rankear conteúdo multimodal relevante
 * para queries do usuário, usando algoritmos de similaridade otimizados.
 */
class MultimodalSearchService
{
    private $contextService;

    public function __construct(ContextService $contextService)
    {
        $this->contextService = $contextService;
    }

    /**
     * Busca conteúdo multimodal relevante baseado em uma query
     *
     * @param Chats $chat Chat para buscar conteúdo
     * @param string $query Query de busca do usuário
     * @param int $limit Número máximo de resultados
     * @return array Conteúdo relevante ordenado por pontuação
     */
    public function searchMultimodalContent(Chats $chat, string $query, int $limit = 5): array
    {
        try {
            $session = $this->contextService->getSession($chat);

            if (!$session) {
                return [];
            }

            $knowledge = $session->knowledge_base ?? [];
            $multimodalContent = $knowledge['multimodal_content'] ?? [];

            if (empty($multimodalContent)) {
                return [];
            }

            // Busca e ranqueamento inteligente
            $relevantContent = $this->findRelevantContent($multimodalContent, $query);

            // Ordenar por relevância e limitar resultados
            usort($relevantContent, function ($a, $b) {
                return $b['relevance_score'] <=> $a['relevance_score'];
            });

            $topResults = array_slice($relevantContent, 0, $limit);

            return array_map(function ($item) {
                unset($item['relevance_score']); // Remove score interno
                return $item;
            }, $topResults);
        } catch (\Exception $e) {
            Log::error('Erro na busca multimodal', [
                'chat_id' => $chat->uuid,
                'query' => $query,
                'error' => $e->getMessage()
            ]);

            return [];
        }
    }

    /**
     * Algoritmo de busca e ranqueamento de conteúdo multimodal
     */
    private function findRelevantContent(array $multimodalContent, string $query): array
    {
        $relevantContent = [];
        $queryLower = strtolower($query);
        $queryWords = $this->extractKeywords($queryLower);

        foreach ($multimodalContent as $content) {
            $relevanceScore = $this->calculateContentRelevance($content, $queryLower, $queryWords);

            if ($relevanceScore > 0.3) { // Threshold mínimo
                $relevantContent[] = array_merge($content, ['relevance_score' => $relevanceScore]);
            }
        }

        return $relevantContent;
    }

    /**
     * Calcula relevância de um item de conteúdo multimodal
     */
    private function calculateContentRelevance(array $content, string $queryLower, array $queryWords): float
    {
        $score = 0.0;

        // Pesos por tipo de conteúdo
        $contentWeights = [
            'transcription' => 2.0,    // Áudio transcrito - alta relevância
            'text' => 1.5,             // Texto de documento
            'agent_context' => 1.2,    // Contexto preparado para agente
            'description' => 1.0,      // Descrições de imagem
            'metadata' => 0.8,         // Metadados
        ];

        foreach ($contentWeights as $field => $weight) {
            if (isset($content['content'][$field])) {
                $fieldContent = strtolower($content['content'][$field]);

                // Correspondência exata da frase
                if (str_contains($fieldContent, $queryLower)) {
                    $score += $weight * 1.5;
                }

                // Correspondência de palavras-chave
                foreach ($queryWords as $word) {
                    if (str_contains($fieldContent, $word)) {
                        $score += $weight * 0.8;
                    }
                }
            }
        }

        // Bonus para correspondências em metadados
        if (isset($content['metadata'])) {
            $metadataString = strtolower(json_encode($content['metadata']));
            if (str_contains($metadataString, $queryLower)) {
                $score += 0.5;
            }
        }

        return min(5.0, $score); // Normalizar para máximo 5.0
    }

    /**
     * Extrai palavras-chave da query para busca
     */
    private function extractKeywords(string $query): array
    {
        // Remove pontuação e converte para array
        $cleanQuery = preg_replace('/[^\w\s]/', ' ', $query);
        $words = explode(' ', $cleanQuery);

        // Filtra palavras relevantes (remove stopwords)
        $stopWords = $this->getStopWords();
        $keywords = array_filter($words, function ($word) use ($stopWords) {
            return strlen($word) > 2 && !in_array(strtolower($word), $stopWords);
        });

        return array_values(array_unique($keywords));
    }

    /**
     * Lista de palavras irrelevantes para busca
     */
    private function getStopWords(): array
    {
        return [
            'de',
            'da',
            'do',
            'dos',
            'das',
            'em',
            'no',
            'na',
            'nos',
            'nas',
            'por',
            'para',
            'com',
            'sem',
            'sobre',
            'entre',
            'desde',
            'até',
            'que',
            'qual',
            'quando',
            'onde',
            'como',
            'por que',
            'porque',
            'um',
            'uma',
            'uns',
            'umas',
            'o',
            'a',
            'os',
            'as',
            'e',
            'ou',
            'mas',
            'porém',
            'então',
            'também',
            'mais',
            'menos',
            'muito',
            'eu',
            'você',
            'ele',
            'ela',
            'nós',
            'eles',
            'elas',
            'me',
            'te',
            'se',
            'meu',
            'minha',
            'teu',
            'tua',
            'nosso',
            'nossa',
            'seu',
            'sua'
        ];
    }

    /**
     * Estatísticas de busca multimodal
     */
    public function getSearchStats(Chats $chat): array
    {
        try {
            $session = $this->contextService->getSession($chat);

            if (!$session) {
                return ['total_content' => 0, 'content_types' => []];
            }

            $knowledge = $session->knowledge_base ?? [];
            $multimodalContent = $knowledge['multimodal_content'] ?? [];

            $stats = [
                'total_content' => count($multimodalContent),
                'content_types' => []
            ];

            // Conta tipos de conteúdo
            foreach ($multimodalContent as $content) {
                $type = $content['metadata']['type'] ?? 'unknown';
                $stats['content_types'][$type] = ($stats['content_types'][$type] ?? 0) + 1;
            }

            return $stats;
        } catch (\Exception $e) {
            Log::error('Erro ao obter estatísticas de busca multimodal', [
                'chat_id' => $chat->uuid,
                'error' => $e->getMessage()
            ]);

            return ['total_content' => 0, 'content_types' => []];
        }
    }
}
