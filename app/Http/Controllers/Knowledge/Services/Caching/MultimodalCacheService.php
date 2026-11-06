<?php

namespace App\Http\Controllers\Knowledge\Services\Caching;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Cache Service específico para conteúdo multimodal
 *
 * Conteúdo multimodal pode ser grande (transcrições de áudio, texto de documentos)
 * então precisa de estratégias de cache específicas.
 */
class MultimodalCacheService
{
    private const CACHE_PREFIX = 'multimodal_';
    private const CONTENT_TTL = 7200; // 2 horas - conteúdo multimodal é mais "permanente"
    private const PROCESSED_TTL = 3600; // 1 hora para metadados de processamento

    /**
     * Cache de conteúdo processado (áudio transcrito, texto extraído)
     */
    public function getProcessedContent(string $fileHash): ?array
    {
        $cacheKey = self::CACHE_PREFIX . 'processed_' . $fileHash;
        return Cache::get($cacheKey);
    }

    /**
     * Define cache de conteúdo processado
     */
    public function setProcessedContent(string $fileHash, array $content): void
    {
        $cacheKey = self::CACHE_PREFIX . 'processed_' . $fileHash;
        Cache::put($cacheKey, $content, self::CONTENT_TTL);
    }

    /**
     * Cache de metadados de processamento
     */
    public function getProcessingMetadata(string $fileHash): ?array
    {
        $cacheKey = self::CACHE_PREFIX . 'metadata_' . $fileHash;
        return Cache::get($cacheKey);
    }

    /**
     * Define cache de metadados de processamento
     */
    public function setProcessingMetadata(string $fileHash, array $metadata): void
    {
        $cacheKey = self::CACHE_PREFIX . 'metadata_' . $fileHash;
        Cache::put($cacheKey, $metadata, self::PROCESSED_TTL);
    }

    /**
     * Gera hash único para arquivo baseado no caminho e tamanho
     */
    public function generateFileHash(string $filePath): string
    {
        if (!file_exists($filePath)) {
            return md5($filePath); // Fallback para arquivos inexistentes
        }

        $fileSize = filesize($filePath);
        $fileMtime = filemtime($filePath);

        return md5($filePath . $fileSize . $fileMtime);
    }

    /**
     * Verifica se arquivo foi modificado comparando hash
     */
    public function isFileChanged(string $filePath, string $cachedHash): bool
    {
        $currentHash = $this->generateFileHash($filePath);
        return $currentHash !== $cachedHash;
    }

    /**
     * Limpa cache de um arquivo específico
     */
    public function clearFileCache(string $fileHash): void
    {
        $processedKey = self::CACHE_PREFIX . 'processed_' . $fileHash;
        $metadataKey = self::CACHE_PREFIX . 'metadata_' . $fileHash;

        Cache::forget($processedKey);
        Cache::forget($metadataKey);
    }

    /**
     * Limpa cache expirado (manutenção)
     */
    public function cleanupExpiredCache(): int
    {
        // Cache::flush() pode ser pesado, então fazemos limpeza seletiva
        // Em produção, considere usar Redis SCAN ou similar

        Log::info('Limpeza de cache multimodal realizada');
        return 0; // Placeholder - implementar conforme necessidade
    }

    /**
     * Estatísticas de uso do cache multimodal
     */
    public function getCacheStats(): array
    {
        return [
            'content_ttl' => self::CONTENT_TTL,
            'processed_ttl' => self::PROCESSED_TTL,
            'cache_prefix' => self::CACHE_PREFIX,
            'estimated_usage' => 'Verificar Redis INFO para detalhes precisos'
        ];
    }

    /**
     * Otimiza cache baseado no padrão de uso
     * Remove caches pouco acessados para liberar memória
     */
    public function optimizeCache(): void
    {
        // Implementar lógica de otimização baseada em LRU ou similar
        // Por enquanto, apenas log
        Log::info('Otimização de cache multimodal solicitada');
    }
}
