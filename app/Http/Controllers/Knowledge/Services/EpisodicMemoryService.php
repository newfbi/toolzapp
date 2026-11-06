<?php

namespace App\Http\Controllers\Knowledge\Services;

use App\Http\Controllers\Knowledge\Repositories\StreamRepository;
use App\Http\Controllers\Knowledge\Services\SemanticSearchService;
use App\Models\Chats;
use App\Models\MemoryStream;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Episodic Memory Service - Memória de Longo Prazo Estruturada.
 *
 * Responsabilidade: Gerenciar memórias episódicas estruturadas onde cada interação
 * vira um episódio com: situação → intenção → sentimento → resumo → resultado.
 *
 * Estrutura de Episódio:
 * {
 *   "episode_id": "uuid",
 *   "created_at": "2025-02-18",
 *   "intent": "career",
 *   "emotion": "motivated",
 *   "summary": "User wants to improve programming expertise.",
 *   "tags": ["career", "laravel", "self-improvement"],
 *   "assistant_advice": "Focus on PHP specialization...",
 *   "resolution": null
 * }
 */
class EpisodicMemoryService
{
    private $repository;
    private $semanticSearch;

    public function __construct(StreamRepository $repository, SemanticSearchService $semanticSearch)
    {
        $this->repository = $repository;
        $this->semanticSearch = $semanticSearch;
    }

    /**
     * Cria um novo episódio a partir de uma interação.
     *
     * @param Chats $chat
     * @param string $userMessage Mensagem do usuário
     * @param string $assistantResponse Resposta do assistente
     * @param array $metadata Metadados adicionais (intent, emotion, etc)
     * @return array Episódio criado
     */
    public function createEpisode(
        Chats $chat,
        string $userMessage,
        string $assistantResponse,
        array $metadata = []
    ): array {
        $session = $this->repository->getOrCreateStream($chat);

        $episode = [
            'episode_id' => Str::uuid()->toString(),
            'created_at' => now()->toIso8601String(),
            'intent' => $metadata['intent'] ?? $this->extractIntent($userMessage),
            'emotion' => $metadata['emotion'] ?? $this->extractEmotion($userMessage),
            'summary' => $metadata['summary'] ?? $this->generateSummary($userMessage, $assistantResponse),
            'tags' => $metadata['tags'] ?? $this->extractTags($userMessage, $assistantResponse),
            'assistant_advice' => $metadata['assistant_advice'] ?? $assistantResponse,
            'resolution' => $metadata['resolution'] ?? null,
            'user_message' => $userMessage,
            'assistant_message' => $assistantResponse,
        ];

        // Adiciona episódio à knowledge_base
        $knowledge = $session->knowledge_base ?? [];
        if (is_string($knowledge)) {
            $decoded = json_decode($knowledge, true);
            $knowledge = json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? $decoded : [];
        }

        if (!isset($knowledge['episodic_memory'])) {
            $knowledge['episodic_memory'] = [];
        }

        if (!is_array($knowledge['episodic_memory'])) {
            $knowledge['episodic_memory'] = [];
        }

        // Adiciona novo episódio
        $knowledge['episodic_memory'][] = $episode;

        // Mantém apenas os últimos 100 episódios (evita crescimento infinito)
        if (count($knowledge['episodic_memory']) > 100) {
            $knowledge['episodic_memory'] = array_slice($knowledge['episodic_memory'], -100);
        }

        // Agrupa episódios por categoria hierárquica
        $knowledge['episodic_memory_by_category'] = $this->groupEpisodesByCategory($knowledge['episodic_memory']);

        $session->knowledge_base = $knowledge;
        $this->repository->save($session);

        return $episode;
    }

    /**
     * Busca episódios relevantes baseado em similaridade semântica.
     *
     * @param Chats $chat
     * @param string $query Query de busca (mensagem do usuário)
     * @param int $limit Número máximo de episódios a retornar
     * @return array Episódios relevantes ordenados por relevância
     */
    public function findRelevantEpisodes(Chats $chat, string $query, int $limit = 3): array
    {
        $session = $this->repository->getLastStream($chat);

        if (!$session) {
            return [];
        }

        $knowledge = $session->knowledge_base ?? [];
        if (is_string($knowledge)) {
            $decoded = json_decode($knowledge, true);
            $knowledge = json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? $decoded : [];
        }

        $episodes = $knowledge['episodic_memory'] ?? [];

        if (empty($episodes)) {
            return [];
        }

        // Usa o SemanticSearchService aprimorado para melhor relevância
        return $this->semanticSearch->calculateSemanticSimilarity($query, $episodes, $limit);
    }

    /**
     * Agrupa episódios por categoria hierárquica.
     *
     * @param array $episodes
     * @return array Episódios agrupados por categoria
     */
    private function groupEpisodesByCategory(array $episodes): array
    {
        $categories = [
            'vida_pessoal' => [],
            'carreira' => [],
            'projetos' => [],
            'metas' => [],
            'preferencias_fixas' => [],
            'outros' => []
        ];

        foreach ($episodes as $episode) {
            $category = $this->categorizeEpisode($episode);
            if (!isset($categories[$category])) {
                $category = 'outros';
            }
            $categories[$category][] = $episode;
        }

        return $categories;
    }

    /**
     * Categoriza um episódio baseado em intent e tags.
     */
    private function categorizeEpisode(array $episode): string
    {
        $intent = strtolower($episode['intent'] ?? '');
        $tags = array_map('strtolower', $episode['tags'] ?? []);

        // Palavras-chave para cada categoria
        $careerKeywords = ['career', 'carreira', 'trabalho', 'emprego', 'profissional', 'skill', 'habilidade'];
        $personalKeywords = ['pessoal', 'personal', 'vida', 'família', 'amigos', 'hobby', 'interesse'];
        $projectKeywords = ['projeto', 'project', 'desenvolvimento', 'development', 'código', 'code'];
        $goalKeywords = ['meta', 'goal', 'objetivo', 'futuro', 'plano', 'planejamento'];

        $allText = $intent . ' ' . implode(' ', $tags);

        if ($this->containsAny($allText, $careerKeywords)) {
            return 'carreira';
        }
        if ($this->containsAny($allText, $projectKeywords)) {
            return 'projetos';
        }
        if ($this->containsAny($allText, $goalKeywords)) {
            return 'metas';
        }
        if ($this->containsAny($allText, $personalKeywords)) {
            return 'vida_pessoal';
        }

        return 'outros';
    }

    /**
     * Verifica se texto contém alguma das palavras-chave.
     */
    private function containsAny(string $text, array $keywords): bool
    {
        foreach ($keywords as $keyword) {
            if (stripos($text, $keyword) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Extrai intenção básica da mensagem.
     */
    private function extractIntent(string $message): string
    {
        $messageLower = strtolower($message);

        $intentKeywords = [
            'career' => ['carreira', 'trabalho', 'emprego', 'profissional', 'skill'],
            'question' => ['como', 'o que', 'qual', 'quando', 'onde', 'por que'],
            'request' => ['preciso', 'quero', 'gostaria', 'pode', 'poderia'],
            'complaint' => ['problema', 'erro', 'não funciona', 'bug'],
            'compliment' => ['obrigado', 'obrigada', 'parabéns', 'excelente', 'ótimo']
        ];

        foreach ($intentKeywords as $intent => $keywords) {
            foreach ($keywords as $keyword) {
                if (stripos($messageLower, $keyword) !== false) {
                    return $intent;
                }
            }
        }

        return 'general';
    }

    /**
     * Extrai emoção básica da mensagem.
     */
    private function extractEmotion(string $message): string
    {
        $messageLower = strtolower($message);

        $emotionKeywords = [
            'hopeful' => ['esperança', 'espero', 'esperança', 'otimista'],
            'curious' => ['curioso', 'curiosidade', 'interessante', 'quero saber'],
            'stressed' => ['estressado', 'preocupado', 'ansioso', 'nervoso', 'problema'],
            'motivated' => ['motivado', 'animado', 'empolgado', 'entusiasmado'],
            'lost' => ['perdido', 'confuso', 'não entendo', 'não sei'],
            'confident' => ['confiante', 'certeza', 'tenho certeza', 'sei que']
        ];

        foreach ($emotionKeywords as $emotion => $keywords) {
            foreach ($keywords as $keyword) {
                if (stripos($messageLower, $keyword) !== false) {
                    return $emotion;
                }
            }
        }

        return 'neutral';
    }

    /**
     * Gera resumo da interação.
     */
    private function generateSummary(string $userMessage, string $assistantResponse): string
    {
        // Resumo simples baseado nas primeiras palavras
        $userWords = explode(' ', trim($userMessage));
        $summary = implode(' ', array_slice($userWords, 0, 10));

        if (strlen($summary) < strlen($userMessage)) {
            $summary .= '...';
        }

        return $summary;
    }

    /**
     * Extrai tags relevantes da interação.
     */
    private function extractTags(string $userMessage, string $assistantResponse): array
    {
        $tags = [];
        $allText = strtolower($userMessage . ' ' . $assistantResponse);

        // Tags comuns baseadas em palavras-chave
        $tagKeywords = [
            'laravel' => ['laravel', 'php'],
            'javascript' => ['javascript', 'js', 'node'],
            'flutter' => ['flutter', 'mobile', 'app'],
            'database' => ['database', 'mysql', 'sql', 'banco de dados'],
            'api' => ['api', 'endpoint', 'rest'],
            'frontend' => ['frontend', 'front-end', 'interface'],
            'backend' => ['backend', 'back-end', 'servidor']
        ];

        foreach ($tagKeywords as $tag => $keywords) {
            foreach ($keywords as $keyword) {
                if (stripos($allText, $keyword) !== false) {
                    $tags[] = $tag;
                    break;
                }
            }
        }

        return array_unique($tags);
    }

    /**
     * Extrai palavras-chave de um texto.
     */
    private function extractKeywords(string $text): array
    {
        // Remove palavras comuns (stop words)
        $stopWords = ['o', 'a', 'os', 'as', 'um', 'uma', 'de', 'do', 'da', 'dos', 'das', 'em', 'no', 'na', 'nos', 'nas', 'para', 'com', 'por', 'que', 'é', 'são', 'foi', 'ser', 'ter', 'tem', 'está', 'estão'];

        $words = explode(' ', $text);
        $keywords = array_filter($words, function ($word) use ($stopWords) {
            $word = trim($word);
            return strlen($word) > 2 && !in_array($word, $stopWords);
        });

        return array_values($keywords);
    }

    /**
     * Calcula score de relevância entre episódio e query.
     */
    private function calculateRelevanceScore(array $episode, array $queryWords, string $queryLower): float
    {
        $score = 0.0;

        // Score baseado em tags
        $episodeTags = array_map('strtolower', $episode['tags'] ?? []);
        foreach ($queryWords as $queryWord) {
            foreach ($episodeTags as $tag) {
                if (stripos($tag, $queryWord) !== false || stripos($queryWord, $tag) !== false) {
                    $score += 0.3;
                }
            }
        }

        // Score baseado em intent
        $episodeIntent = strtolower($episode['intent'] ?? '');
        foreach ($queryWords as $queryWord) {
            if (stripos($episodeIntent, $queryWord) !== false) {
                $score += 0.2;
            }
        }

        // Score baseado em resumo
        $episodeSummary = strtolower($episode['summary'] ?? '');
        foreach ($queryWords as $queryWord) {
            if (stripos($episodeSummary, $queryWord) !== false) {
                $score += 0.1;
            }
        }

        // Score baseado em mensagem do usuário do episódio
        $episodeUserMessage = strtolower($episode['user_message'] ?? '');
        $commonWords = array_intersect($queryWords, explode(' ', $episodeUserMessage));
        $score += count($commonWords) * 0.15;

        return min(1.0, $score); // Normaliza para máximo 1.0
    }
}
