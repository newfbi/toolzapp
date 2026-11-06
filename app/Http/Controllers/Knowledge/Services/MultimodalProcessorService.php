<?php

namespace App\Http\Controllers\Knowledge\Services;

use App\Helpers\AudioTranscriptionHelper;
use App\Helpers\DocumentAnalyzer;
use App\Helpers\FileUploadHelper;
use App\Http\Controllers\Knowledge\Services\Caching\MultimodalCacheService;
use App\Models\Agents;
use App\Models\Chats;
use App\Support\AiModelCatalog;
use Illuminate\Support\Facades\Log;

/**
 * Multimodal Processor Service - Processamento Integrado de Áudio, Imagens e Documentos
 *
 * Responsabilidade: Coordenar processamento multimodal e integrar com o sistema Knowledge
 * para enriquecer memórias com conteúdo extraído de diferentes tipos de mídia.
 */
class MultimodalProcessorService
{
    private $contextService;
    private $multimodalCache;

    public function __construct(
        ContextService $contextService,
        MultimodalCacheService $multimodalCache
    ) {
        $this->contextService = $contextService;
        $this->multimodalCache = $multimodalCache;
    }

    /**
     * Processa arquivo multimodal e retorna contexto enriquecido
     *
     * @param array $fileData Dados do arquivo (path, type, metadata, mime_type)
     * @param Chats $chat Chat atual
     * @param Agents $agent Agente
     * @return array Contexto multimodal processado
     */
    public function processMultimodalFile(array $fileData, Chats $chat, Agents $agent): array
    {
        try {
            $filePath = $fileData['path'] ?? '';
            $metadata = $fileData['metadata'] ?? [];

            // Detecção automática do tipo de arquivo se não fornecido
            $fileType = $fileData['type'] ?? null;
            if (!$fileType || $fileType === 'unknown') {
                $fileType = $this->detectFileType($filePath, $fileData['mime_type'] ?? null, $fileData['extension'] ?? null);
            }

            // Gera hash único do arquivo para cache
            $fileHash = $this->multimodalCache->generateFileHash($filePath);

            // Verifica se já existe cache válido
            $cachedContent = $this->multimodalCache->getProcessedContent($fileHash);
            if ($cachedContent !== null) {

                // Ainda enriquece a memória mesmo com cache
                $this->enrichMemoryWithContent($chat, $cachedContent, $fileType);
                return $cachedContent;
            }

            // Processa o arquivo
            $processedContent = match ($fileType) {
                'audio' => $this->processAudioFile($filePath, $metadata),
                'document' => $this->processDocumentFile($filePath, $metadata),
                'image' => $this->processImageFile($filePath, $metadata),
                default => $this->processUnknownFile($filePath, $metadata)
            };

            // Cache do resultado se foi bem-sucedido
            if ($processedContent['success']) {
                $this->multimodalCache->setProcessedContent($fileHash, $processedContent);
            }

            // Enriquecer memória com conteúdo processado
            if ($processedContent['success']) {
                $this->enrichMemoryWithContent($chat, $processedContent, $fileType);
            }

            return $processedContent;
        } catch (\Exception $e) {
            Log::error('Erro no processamento multimodal', [
                'chat_id' => $chat->uuid,
                'file_path' => $filePath,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Erro ao processar arquivo: ' . $e->getMessage(),
                'content' => null,
                'metadata' => $metadata
            ];
        }
    }

    /**
     * Processa arquivo de áudio
     */
    private function processAudioFile(string $filePath, array $metadata = []): array
    {
        if (!AudioTranscriptionHelper::isAvailable()) {
            return [
                'success' => false,
                'error' => 'Serviço de transcrição de áudio não disponível',
                'content' => null,
                'metadata' => array_merge($metadata, ['type' => 'audio'])
            ];
        }

        try {
            // Transcrição completa com análise emocional
            $transcription = AudioTranscriptionHelper::analyze($filePath);

            if (!$transcription || !isset($transcription['transcription'])) {
                return [
                    'success' => false,
                    'error' => 'Falha na transcrição do áudio',
                    'content' => null,
                    'metadata' => array_merge($metadata, ['type' => 'audio'])
                ];
            }

            $content = [
                'transcription' => $transcription['transcription'],
                'duration' => $transcription['duration'] ?? null,
                'emotional_context' => $transcription['emotional_context'] ?? null,
                'confidence' => $transcription['confidence'] ?? null,
                'language' => $transcription['language'] ?? 'pt-BR'
            ];

            return [
                'success' => true,
                'content' => $content,
                'metadata' => array_merge($metadata, [
                    'type' => 'audio',
                    'duration' => $content['duration'],
                    'language' => $content['language']
                ])
            ];
        } catch (\Exception $e) {
            Log::error('Erro na transcrição de áudio', [
                'file_path' => $filePath,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Erro na transcrição: ' . $e->getMessage(),
                'content' => null,
                'metadata' => array_merge($metadata, ['type' => 'audio'])
            ];
        }
    }

    /**
     * Processa arquivo de documento
     */
    private function processDocumentFile(string $filePath, array $metadata = []): array
    {
        try {
            // Usar DocumentProcessingService para extrair conteúdo
            $documentProcessor = app(\App\Services\DocumentProcessingService::class);
            $extractedContent = $documentProcessor->processDocumentFromPath($filePath);

            if (!$extractedContent['success']) {
                return [
                    'success' => false,
                    'error' => $extractedContent['error'] ?? 'Erro na extração do documento',
                    'content' => null,
                    'metadata' => array_merge($metadata, ['type' => 'document'])
                ];
            }

            // Usar DocumentAnalyzer para preparar contexto para o agente
            $analyzer = new DocumentAnalyzer();
            $agentContext = $analyzer->prepareDocumentForAgent($extractedContent);

            $content = [
                'text' => $extractedContent['text'],
                'word_count' => $extractedContent['word_count'] ?? str_word_count($extractedContent['text']),
                'pages' => $extractedContent['pages'] ?? null,
                'sections' => $extractedContent['sections'] ?? null,
                'agent_context' => $agentContext,
                'structure' => $extractedContent['structure'] ?? null
            ];

            return [
                'success' => true,
                'content' => $content,
                'metadata' => array_merge($metadata, [
                    'type' => 'document',
                    'word_count' => $content['word_count'],
                    'pages' => $content['pages'],
                    'sections' => $content['sections']
                ])
            ];
        } catch (\Exception $e) {
            Log::error('Erro no processamento de documento', [
                'file_path' => $filePath,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Erro no processamento do documento: ' . $e->getMessage(),
                'content' => null,
                'metadata' => array_merge($metadata, ['type' => 'document'])
            ];
        }
    }

    /**
     * Processa arquivo de imagem com análise automática usando IA
     */
    private function processImageFile(string $filePath, array $metadata = []): array
    {
        try {
            // Validação básica da imagem
            $imageInfo = getimagesize($filePath);

            if (!$imageInfo) {
                return [
                    'success' => false,
                    'error' => 'Arquivo de imagem inválido ou corrompido',
                    'content' => null,
                    'metadata' => array_merge($metadata, ['type' => 'image'])
                ];
            }

            $content = [
                'width' => $imageInfo[0],
                'height' => $imageInfo[1],
                'mime_type' => $imageInfo['mime'],
                'description' => null,
                'analysis' => null
            ];

            // Tenta análise automática com IA se disponível
            try {
                $imageTranscriptionService = app(\App\Services\ImageTranscriptionService::class);
                $analysisResult = null;

                // Tenta diferentes métodos de análise baseado no caminho do arquivo
                $publicPath = storage_path('app/public/');

                if (str_starts_with($filePath, $publicPath)) {
                    // Caminho absoluto dentro do storage/app/public - converte para relativo
                    $relativePath = str_replace($publicPath, '', $filePath);
                    $analysisResult = $imageTranscriptionService->analyzeImageWithContext($relativePath, 'public');
                } elseif (file_exists($filePath)) {
                    // Caminho absoluto - usar método específico para caminhos absolutos
                    $analysisResult = $imageTranscriptionService->analyzeImageFromAbsolutePath($filePath);
                } else {
                    // Tenta como caminho relativo
                    $analysisResult = $imageTranscriptionService->analyzeImageWithContext($filePath, 'public');
                }

                if ($analysisResult) {
                    // Processa resultado baseado no formato retornado
                    if (isset($analysisResult['success']) && $analysisResult['success']) {
                        // Formato analyzeImageWithContext
                        $content['description'] = $analysisResult['analysis'] ?? $analysisResult['context'] ?? null;
                        $content['analysis'] = $analysisResult['analysis'] ?? null;
                        $content['model_used'] = $analysisResult['model_used'] ?? AiModelCatalog::default();
                    } elseif (isset($analysisResult['analysis'])) {
                        // Formato analyzeImage
                        $content['description'] = $analysisResult['analysis'] ?? null;
                        $content['analysis'] = $analysisResult['analysis'] ?? null;
                        $content['model_used'] = $analysisResult['model_used'] ?? AiModelCatalog::default();
                    }

                    if (!empty($content['description'])) {
                        $content['analysis_length'] = strlen($content['description']);

                        Log::info('Imagem analisada com sucesso usando IA', [
                            'file_path' => $filePath,
                            'model' => $content['model_used'] ?? AiModelCatalog::default(),
                            'analysis_length' => $content['analysis_length']
                        ]);
                    }
                }

                // Fallback se análise não disponível ou falhou
                if (empty($content['description'])) {
                    $content['description'] = "Imagem recebida ({$content['width']}x{$content['height']} pixels). Análise automática não disponível no momento.";

                    Log::debug('Análise de imagem não disponível, usando fallback', [
                        'file_path' => $filePath
                    ]);
                }
            } catch (\Exception $e) {
                // Se o serviço não estiver disponível, usa fallback
                Log::warning('Serviço de análise de imagem não disponível', [
                    'file_path' => $filePath,
                    'error' => $e->getMessage()
                ]);

                $content['description'] = "Imagem recebida ({$content['width']}x{$content['height']} pixels). Análise automática não disponível.";
            }

            return [
                'success' => true,
                'content' => $content,
                'metadata' => array_merge($metadata, [
                    'type' => 'image',
                    'width' => $content['width'],
                    'height' => $content['height'],
                    'mime_type' => $content['mime_type'],
                    'analyzed' => !empty($content['analysis']),
                    'model_used' => $content['model_used'] ?? null
                ])
            ];
        } catch (\Exception $e) {
            Log::error('Erro no processamento de imagem', [
                'file_path' => $filePath,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Erro no processamento da imagem: ' . $e->getMessage(),
                'content' => null,
                'metadata' => array_merge($metadata, ['type' => 'image'])
            ];
        }
    }

    /**
     * Processa arquivo de tipo desconhecido
     */
    private function processUnknownFile(string $filePath, array $metadata = []): array
    {
        return [
            'success' => false,
            'error' => 'Tipo de arquivo não suportado para processamento multimodal',
            'content' => null,
            'metadata' => array_merge($metadata, ['type' => 'unknown'])
        ];
    }

    /**
     * Enriquece a memória do chat com conteúdo multimodal processado
     */
    private function enrichMemoryWithContent(Chats $chat, array $processedContent, string $contentType): void
    {
        try {
            $session = $this->contextService->getSession($chat);

            if (!$session) {
                Log::warning('Sessão não encontrada para enriquecer memória', [
                    'chat_id' => $chat->uuid
                ]);
                return;
            }

            $knowledge = $session->knowledge_base ?? [];

            // Estrutura o conteúdo multimodal na knowledge base
            if (!isset($knowledge['multimodal_content'])) {
                $knowledge['multimodal_content'] = [];
            }

            $multimodalEntry = [
                'timestamp' => now()->toIso8601String(),
                'type' => $contentType,
                'content' => $processedContent['content'],
                'metadata' => $processedContent['metadata']
            ];

            $knowledge['multimodal_content'][] = $multimodalEntry;

            // Limitar histórico multimodal (últimos 20 itens)
            if (count($knowledge['multimodal_content']) > 20) {
                $knowledge['multimodal_content'] = array_slice($knowledge['multimodal_content'], -20);
            }

            $session->knowledge_base = $knowledge;
            $this->contextService->getRepository()->save($session);

            Log::info('Memória enriquecida com conteúdo multimodal', [
                'chat_id' => $chat->uuid,
                'content_type' => $contentType,
                'session_id' => $session->id
            ]);
        } catch (\Exception $e) {
            Log::error('Erro ao enriquecer memória com conteúdo multimodal', [
                'chat_id' => $chat->uuid,
                'content_type' => $contentType,
                'error' => $e->getMessage()
            ]);
        }
    }


    /**
     * Detecta automaticamente o tipo de arquivo baseado em mime type, extensão ou análise do arquivo
     *
     * @param string $filePath Caminho do arquivo
     * @param string|null $mimeType MIME type fornecido (opcional)
     * @param string|null $extension Extensão do arquivo (opcional)
     * @return string Tipo detectado: 'audio', 'document', 'image' ou 'unknown'
     */
    private function detectFileType(string $filePath, ?string $mimeType = null, ?string $extension = null): string
    {
        // 1. Tentar detectar por MIME type fornecido
        if ($mimeType) {
            $detectedType = \App\Helpers\FileUploadHelper::getFileTypeFromMime($mimeType);
            if ($detectedType) {
                return $detectedType;
            }
        }

        // 2. Tentar detectar por extensão se não tiver MIME type
        if (!$mimeType && $extension) {
            $detectedType = $this->detectFileTypeFromExtension($extension);
            if ($detectedType) {
                return $detectedType;
            }
        }

        // 3. Tentar detectar por extensão do arquivo se não fornecido
        if (!$extension && file_exists($filePath)) {
            $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
            $detectedType = $this->detectFileTypeFromExtension($extension);
            if ($detectedType) {
                return $detectedType;
            }
        }

        // 4. Tentar detectar por MIME type do arquivo usando PHP
        if (file_exists($filePath)) {
            $detectedMimeType = mime_content_type($filePath);
            if ($detectedMimeType) {
                $detectedType = \App\Helpers\FileUploadHelper::getFileTypeFromMime($detectedMimeType);
                if ($detectedType) {
                    return $detectedType;
                }
            }
        }

        // 5. Fallback: tentar detectar por análise do conteúdo do arquivo
        if (file_exists($filePath)) {
            $detectedType = $this->detectFileTypeFromContent($filePath);
            if ($detectedType) {
                return $detectedType;
            }
        }

        return 'unknown';
    }

    /**
     * Detecta tipo de arquivo baseado na extensão
     */
    private function detectFileTypeFromExtension(string $extension): ?string
    {
        $extension = strtolower(trim($extension, '.'));

        $extensionMap = [
            'audio' => ['mp3', 'wav', 'm4a', 'ogg', 'aac', 'webm', 'flac', 'wma', 'opus'],
            'image' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'ico', 'tiff', 'tif'],
            'document' => ['pdf', 'doc', 'docx', 'txt', 'rtf', 'odt', 'xls', 'xlsx', 'ppt', 'pptx', 'csv']
        ];

        foreach ($extensionMap as $type => $extensions) {
            if (in_array($extension, $extensions)) {
                return $type;
            }
        }

        return null;
    }

    /**
     * Detecta tipo de arquivo analisando o conteúdo (último recurso)
     */
    private function detectFileTypeFromContent(string $filePath): ?string
    {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            return null;
        }

        // Lê primeiros bytes do arquivo para identificar assinatura
        $handle = fopen($filePath, 'rb');
        if (!$handle) {
            return null;
        }

        $header = fread($handle, 12);
        fclose($handle);

        if (strlen($header) < 4) {
            return null;
        }

        // Verifica assinaturas conhecidas (magic numbers)
        // PDF
        if (substr($header, 0, 4) === '%PDF') {
            return 'document';
        }

        // Imagens
        // JPEG: FF D8 FF
        if (substr($header, 0, 3) === "\xFF\xD8\xFF") {
            return 'image';
        }
        // PNG: 89 50 4E 47
        if (substr($header, 0, 4) === "\x89\x50\x4E\x47") {
            return 'image';
        }
        // GIF: 47 49 46 38
        if (substr($header, 0, 4) === "\x47\x49\x46\x38") {
            return 'image';
        }
        // WebP: RIFF...WEBP
        if (substr($header, 0, 4) === 'RIFF' && substr($header, 8, 4) === 'WEBP') {
            return 'image';
        }

        // Áudio
        // MP3: ID3 ou FF FB/FF F3/FF F2
        if (
            substr($header, 0, 3) === 'ID3' ||
            (substr($header, 0, 2) === "\xFF\xFB") ||
            (substr($header, 0, 2) === "\xFF\xF3") ||
            (substr($header, 0, 2) === "\xFF\xF2")
        ) {
            return 'audio';
        }
        // WAV: RIFF...WAVE
        if (substr($header, 0, 4) === 'RIFF' && substr($header, 8, 4) === 'WAVE') {
            return 'audio';
        }
        // OGG: OggS
        if (substr($header, 0, 4) === 'OggS') {
            return 'audio';
        }

        // Documentos Office
        // DOCX/XLSX/PPTX: PK (ZIP signature)
        if (substr($header, 0, 2) === 'PK') {
            // Verifica se é um arquivo Office Open XML
            $zip = new \ZipArchive();
            if ($zip->open($filePath) === true) {
                // Verifica se contém estrutura de documento Office
                if (
                    $zip->locateName('word/') !== false ||
                    $zip->locateName('xl/') !== false ||
                    $zip->locateName('ppt/') !== false
                ) {
                    $zip->close();
                    return 'document';
                }
                $zip->close();
            }
        }

        return null;
    }

    /**
     * Verifica se um tipo de arquivo é suportado para processamento multimodal
     */
    public static function isSupportedFileType(string $fileType): bool
    {
        return in_array($fileType, ['audio', 'document', 'image']);
    }

    /**
     * Obtém capacidades do sistema multimodal
     */
    public static function getCapabilities(): array
    {
        return [
            'audio' => [
                'available' => AudioTranscriptionHelper::isAvailable(),
                'service' => AudioTranscriptionHelper::getServiceInfo(),
                'supported_formats' => ['webm', 'mp3', 'wav', 'm4a', 'ogg', 'aac'],
                'max_size' => '25MB'
            ],
            'document' => [
                'available' => true,
                'supported_formats' => ['pdf', 'docx', 'doc', 'txt'],
                'features' => ['text_extraction', 'structure_analysis', 'metadata_extraction']
            ],
            'image' => [
                'available' => true,
                'supported_formats' => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
                'features' => ['basic_info', 'dimensions', 'mime_type']
            ]
        ];
    }

    /**
     * Limpa cache de um arquivo específico
     */
    public function clearFileCache(string $filePath): void
    {
        $fileHash = $this->multimodalCache->generateFileHash($filePath);
        $this->multimodalCache->clearFileCache($fileHash);
    }

    /**
     * Obtém estatísticas do cache multimodal
     */
    public function getCacheStats(): array
    {
        return $this->multimodalCache->getCacheStats();
    }
}
