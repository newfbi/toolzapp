<?php

namespace App\Http\Controllers\Knowledge\Services;

use Illuminate\Support\Facades\Log;

/**
 * Serviço de Busca Semântica Aprimorada
 *
 * Abordagem híbrida: Mantém performance com melhorias inteligentes
 * sem a complexidade de embeddings vetoriais completos
 */
class SemanticSearchService
{
    /**
     * Calcula similaridade semântica entre queries usando abordagem híbrida
     *
     * Melhorias sobre o método anterior:
     * - Peso contextual baseado em posição das palavras
     * - Similaridade de conceitos relacionados
     * - Penalização de palavras de parada
     * - Bonus para frases exatas
     */
    public function calculateSemanticSimilarity(string $query, array $episodes, int $limit = 3): array
    {
        $queryFeatures = $this->extractQueryFeatures($query);
        $scoredEpisodes = [];

        foreach ($episodes as $episode) {
            $score = $this->calculateEnhancedScore($queryFeatures, $episode);

            if ($score > 0.2) { // Threshold reduzido pois o algoritmo é melhor
                $scoredEpisodes[] = [
                    'episode' => $episode,
                    'score' => $score
                ];
            }
        }

        // Ordena por score decrescente
        usort($scoredEpisodes, function ($a, $b) {
            return $b['score'] <=> $a['score'];
        });

        $relevantEpisodes = array_slice($scoredEpisodes, 0, $limit);

        return array_map(function ($item) {
            return $item['episode'];
        }, $relevantEpisodes);
    }

    /**
     * Extrai features semânticas da query
     */
    private function extractQueryFeatures(string $query): array
    {
        $queryLower = strtolower($query);

        // Remove pontuação
        $queryClean = preg_replace('/[^\w\s]/', ' ', $queryLower);
        $words = explode(' ', $queryClean);
        $words = array_filter($words, function ($word) {
            return strlen($word) > 1 && !$this->isStopWord($word);
        });

        // Identifica frases importantes (entre aspas)
        preg_match_all('/"([^"]+)"/', $query, $quotedPhrases);

        // Identifica intenção primária
        $primaryIntent = $this->identifyPrimaryIntent($words);

        // Identifica entidades/conceitos
        $concepts = $this->identifyConcepts($words);

        return [
            'words' => array_values($words),
            'quoted_phrases' => $quotedPhrases[1] ?? [],
            'primary_intent' => $primaryIntent,
            'concepts' => $concepts,
            'word_positions' => $this->getWordPositions($words, $queryLower)
        ];
    }

    /**
     * Calcula score aprimorado baseado em múltiplos fatores
     */
    private function calculateEnhancedScore(array $queryFeatures, array $episode): float
    {
        $score = 0.0;

        // 1. Score de frases exatas (peso alto)
        $score += $this->calculatePhraseScore($queryFeatures['quoted_phrases'], $episode) * 0.4;

        // 2. Score de intenção primária
        $score += $this->calculateIntentScore($queryFeatures['primary_intent'], $episode) * 0.25;

        // 3. Score de conceitos relacionados
        $score += $this->calculateConceptScore($queryFeatures['concepts'], $episode) * 0.2;

        // 4. Score de palavras-chave com peso posicional
        $score += $this->calculateKeywordScore($queryFeatures, $episode) * 0.15;

        return min(1.0, $score);
    }

    /**
     * Score baseado em frases exatas
     */
    private function calculatePhraseScore(array $phrases, array $episode): float
    {
        $score = 0.0;

        foreach ($phrases as $phrase) {
            $episodeText = strtolower(
                ($episode['user_message'] ?? '') . ' ' .
                    ($episode['summary'] ?? '') . ' ' .
                    implode(' ', $episode['tags'] ?? [])
            );

            if (str_contains($episodeText, strtolower($phrase))) {
                $score += 1.0;
            }
        }

        return min(1.0, $score);
    }

    /**
     * Score baseado em intenção primária
     */
    private function calculateIntentScore(string $queryIntent, array $episode): float
    {
        $episodeIntent = strtolower($episode['intent'] ?? '');

        if ($queryIntent === $episodeIntent) {
            return 1.0;
        }

        // Intenções relacionadas
        $relatedIntents = [
            'career' => ['job', 'work', 'professional'],
            'question' => ['help', 'learn', 'understand'],
            'project' => ['development', 'code', 'build'],
        ];

        if (
            isset($relatedIntents[$queryIntent]) &&
            in_array($episodeIntent, $relatedIntents[$queryIntent])
        ) {
            return 0.7;
        }

        return 0.0;
    }

    /**
     * Score baseado em conceitos relacionados
     */
    private function calculateConceptScore(array $concepts, array $episode): float
    {
        $score = 0.0;
        $episodeTags = array_map('strtolower', $episode['tags'] ?? []);

        foreach ($concepts as $concept) {
            if (in_array(strtolower($concept), $episodeTags)) {
                $score += 0.8;
            }

            // Verifica similaridade de conceitos
            foreach ($episodeTags as $tag) {
                if ($this->areConceptsRelated($concept, $tag)) {
                    $score += 0.4;
                }
            }
        }

        return min(1.0, $score);
    }

    /**
     * Score baseado em palavras-chave com peso posicional
     */
    private function calculateKeywordScore(array $queryFeatures, array $episode): float
    {
        $score = 0.0;
        $words = $queryFeatures['words'];
        $wordPositions = $queryFeatures['word_positions'];

        $episodeText = strtolower(
            ($episode['user_message'] ?? '') . ' ' .
                ($episode['summary'] ?? '') . ' ' .
                ($episode['assistant_advice'] ?? '')
        );

        foreach ($words as $index => $word) {
            $position = $wordPositions[$index] ?? 0.5;
            $positionWeight = 1 + ($position * 0.5); // Bonus para palavras no início

            if (str_contains($episodeText, $word)) {
                $score += (0.1 * $positionWeight);
            }
        }

        return min(1.0, $score);
    }

    /**
     * Identifica intenção primária da query
     */
    private function identifyPrimaryIntent(array $words): string
    {
        $intentPatterns = [
            'career' => ['carreira', 'trabalho', 'emprego', 'profissional', 'skill', 'habilidade'],
            'question' => ['como', 'o que', 'qual', 'quando', 'onde', 'por que', 'help'],
            'project' => ['projeto', 'desenvolver', 'criar', 'build', 'code', 'programar'],
            'personal' => ['pessoal', 'vida', 'família', 'amigos', 'hobby'],
            'goal' => ['meta', 'objetivo', 'futuro', 'plano', 'sonho'],
        ];

        foreach ($intentPatterns as $intent => $keywords) {
            foreach ($words as $word) {
                if (in_array($word, $keywords)) {
                    return $intent;
                }
            }
        }

        return 'general';
    }

    /**
     * Identifica conceitos importantes da query
     */
    private function identifyConcepts(array $words): array
    {
        $concepts = [];

        // Tecnologias/frameworks
        $techConcepts = ['php', 'laravel', 'javascript', 'react', 'vue', 'python', 'node', 'docker'];
        $concepts = array_merge($concepts, array_intersect($words, $techConcepts));

        // Áreas de atuação
        $fieldConcepts = ['backend', 'frontend', 'fullstack', 'mobile', 'web', 'api'];
        $concepts = array_merge($concepts, array_intersect($words, $fieldConcepts));

        return array_unique($concepts);
    }

    /**
     * Calcula peso posicional das palavras
     */
    private function getWordPositions(array $words, string $originalQuery): array
    {
        $positions = [];

        foreach ($words as $index => $word) {
            $pos = strpos($originalQuery, $word);
            if ($pos !== false) {
                // Normaliza posição (0 = início, 1 = fim)
                $positions[$index] = $pos / strlen($originalQuery);
            } else {
                $positions[$index] = 0.5; // Meio da query
            }
        }

        return $positions;
    }

    /**
     * Verifica se dois conceitos são relacionados
     */
    private function areConceptsRelated(string $concept1, string $concept2): bool
    {
        $relatedConcepts = [
            'php' => ['laravel', 'backend', 'web'],
            'laravel' => ['php', 'backend', 'framework'],
            'javascript' => ['frontend', 'react', 'vue', 'node'],
            'react' => ['javascript', 'frontend', 'component'],
            'python' => ['backend', 'data', 'ml', 'ai'],
        ];

        $concept1 = strtolower($concept1);
        $concept2 = strtolower($concept2);

        return isset($relatedConcepts[$concept1]) &&
            in_array($concept2, $relatedConcepts[$concept1]);
    }

    /**
     * Verifica se palavra é stop word
     */
    private function isStopWord(string $word): bool
    {
        $stopWords = [
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
            'muito'
        ];

        return in_array(strtolower($word), $stopWords);
    }
}
