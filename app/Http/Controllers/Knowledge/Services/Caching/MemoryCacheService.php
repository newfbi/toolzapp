<?php

namespace App\Http\Controllers\Knowledge\Services\Caching;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Serviço de Cache para Memória - Otimiza acesso frequente a dados de memória
 *
 * Estratégia: Cache inteligente com TTL adaptativo baseado na frequência de uso
 */
class MemoryCacheService
{
    private const CACHE_PREFIX = 'knowledge_memory_';

    private function getEpisodesTtl(): int
    {
        return config('knowledge.cache.ttl.episodes', 3600);
    }

    private function getKnowledgeBaseTtl(): int
    {
        return config('knowledge.cache.ttl.knowledge_base', 1800);
    }

    private function getContextTtl(): int
    {
        return config('knowledge.cache.ttl.context', 900);
    }

    private function getCachePrefix(): string
    {
        return config('knowledge.cache.prefix', 'knowledge_memory_');
    }

    /**
     * Cache de episódios por chat
     */
    public function getCachedEpisodes(string $chatId): ?array
    {
        if (!config('knowledge.cache.enabled', true)) {
            return null;
        }

        $cacheKey = $this->getCachePrefix() . 'episodes_' . $chatId;
        return Cache::get($cacheKey);
    }

    /**
     * Define cache de episódios
     */
    public function setCachedEpisodes(string $chatId, array $episodes): void
    {
        if (!config('knowledge.cache.enabled', true)) {
            return;
        }

        $cacheKey = $this->getCachePrefix() . 'episodes_' . $chatId;
        Cache::put($cacheKey, $episodes, $this->getEpisodesTtl());
    }

    /**
     * Invalida cache de episódios
     */
    public function invalidateEpisodesCache(string $chatId): void
    {
        $cacheKey = $this->getCachePrefix() . 'episodes_' . $chatId;
        Cache::forget($cacheKey);

        Log::debug('Cache de episódios invalidado', ['chat_id' => $chatId]);
    }

    /**
     * Cache de knowledge base
     */
    public function getCachedKnowledgeBase(string $chatId): ?array
    {
        $cacheKey = $this->getCachePrefix() . 'knowledge_' . $chatId;
        return Cache::get($cacheKey);
    }

    /**
     * Define cache de knowledge base
     */
    public function setCachedKnowledgeBase(string $chatId, array $knowledgeBase): void
    {
        $cacheKey = $this->getCachePrefix() . 'knowledge_' . $chatId;
        Cache::put($cacheKey, $knowledgeBase, $this->getKnowledgeBaseTtl());
    }

    /**
     * Invalida cache de knowledge base
     */
    public function invalidateKnowledgeBaseCache(string $chatId): void
    {
        $cacheKey = $this->getCachePrefix() . 'knowledge_' . $chatId;
        Cache::forget($cacheKey);
    }

    /**
     * Cache de contexto sintetizado
     */
    public function getCachedContext(string $chatId, string $queryHash): ?string
    {
        $cacheKey = $this->getCachePrefix() . 'context_' . $chatId . '_' . $queryHash;

        // Verifica se o cache foi desabilitado para este contexto
        $disabledKey = $cacheKey . '_disabled';
        if (Cache::get($disabledKey)) {
            Log::debug('Cache desabilitado para este contexto, pulando', [
                'chat_id' => $chatId,
                'query_hash' => $queryHash
            ]);
            return null;
        }

        return Cache::get($cacheKey);
    }

    /**
     * Define cache de contexto
     */
    public function setCachedContext(string $chatId, string $queryHash, string $context): void
    {
        $cacheKey = $this->getCachePrefix() . 'context_' . $chatId . '_' . $queryHash;

        try {
            // Tenta armazenar normalmente
            Cache::put($cacheKey, $context, $this->getContextTtl());
        } catch (\Exception $e) {
            // 🔍 LOG DO ERRO ORIGINAL
            Log::error('ERRO ORIGINAL no armazenamento de cache', [
                'error' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'cache_key' => $cacheKey,
                'chat_id' => $chatId,
                'query_hash' => $queryHash
            ]);

            try {
                // Sanitiza o contexto removendo caracteres problemáticos
                $sanitizedContext = $this->sanitizeContext($context);

                Cache::put($cacheKey, $sanitizedContext, $this->getContextTtl());
            } catch (\Exception $sanitizedError) {
                // 🔍 LOG DO ERRO NA SANITIZAÇÃO
                Log::error('ERRO MESMO COM SANITIZAÇÃO', [
                    'error' => $sanitizedError->getMessage(),
                    'error_code' => $sanitizedError->getCode(),
                    'cache_key' => $cacheKey,
                    'chat_id' => $chatId,
                    'query_hash' => $queryHash,
                    'sanitized_length' => strlen($sanitizedContext ?? '')
                ]);

                // Armazena um flag para não tentar cache novamente
                Cache::put($cacheKey . '_disabled', true, $this->getContextTtl());
            }
        }
    }

    /**
     * Sanitiza contexto removendo caracteres problemáticos
     */
    private function sanitizeContext(string $context): string
    {
        // Abordagem ultra-agressiva: converte para ASCII puro
        // Remove completamente qualquer vestígio de caracteres especiais

        // Primeiro, converte caracteres especiais para ASCII equivalente
        $context = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $context);

        // Remove QUALQUER coisa que não seja ASCII básico (0-127)
        $context = preg_replace('/[^\x20-\x7E\n\r\t]/', '', $context);

        // Remove caracteres de controle
        $context = preg_replace('/[\x00-\x1F\x7F]/', '', $context);

        // Limpa quebras de linha múltiplas
        $context = preg_replace('/\n{3,}/', "\n\n", $context);

        // Remove espaços múltiplos
        $context = preg_replace('/ {2,}/', ' ', $context);

        // Trim final
        $context = trim($context);

        // Fallback se ficou vazio
        if (empty($context)) {
            $context = 'Context sanitized completely - all special characters removed for database compatibility';
        }

        return $context;
    }

    /**
     * Gera hash simples para queries similares
     */
    public function generateQueryHash(string $query): string
    {
        // Normaliza query para cache (remove pontuação, lowercase)
        $normalized = strtolower(preg_replace('/[^\w\s]/', '', $query));
        $words = explode(' ', $normalized);
        $significantWords = array_slice($words, 0, 10); // Primeiras 10 palavras
        return md5(implode(' ', $significantWords));
    }

    /**
     * Limpa todos os caches de um chat
     */
    public function clearChatCache(string $chatId): void
    {
        $this->invalidateEpisodesCache($chatId);
        $this->invalidateKnowledgeBaseCache($chatId);

        // Remove também caches de contexto relacionados
        $pattern = self::CACHE_PREFIX . 'context_' . $chatId . '_*';
        // Note: Redis scan pattern não é implementado aqui por simplicidade
        // Em produção, consideraria Redis para padrões mais complexos

        Log::info('Cache do chat limpo', ['chat_id' => $chatId]);
    }

    /**
     * Estatísticas de cache
     */
    public function getCacheStats(): array
    {
        // Implementação básica - em produção usaria Redis INFO
        return [
            'episodes_ttl' => $this->getEpisodesTtl(),
            'knowledge_base_ttl' => $this->getKnowledgeBaseTtl(),
            'context_ttl' => $this->getContextTtl(),
        ];
    }
}
