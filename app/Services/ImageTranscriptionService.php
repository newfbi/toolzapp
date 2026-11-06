<?php

namespace App\Services;

use App\Models\Settings;
use App\Support\AiModelCatalog;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use OpenAI;
use GuzzleHttp\Client;

class ImageTranscriptionService
{
    /**
     * Analisa imagem usando OpenAI Vision API
     */
    public function analyzeImage($imagePath, $disk = 'public'): ?array
    {
        try {
            $fullPath = storage_path('app/' . $disk . '/' . $imagePath);

            if (!file_exists($fullPath)) {
                Log::error('ImageTranscriptionService: Arquivo de imagem não encontrado para análise', [
                    'full_path' => $fullPath
                ]);
                return null;
            }

            // Validar arquivo
            if (!$this->validateImageFile($fullPath)) {
                return null;
            }

            $client = $this->getOpenAiClient();
            if (!$client) {
                return null;
            }

            // Analisar com o modelo Vision
            $result = $this->analyzeWithVisionAPI($fullPath, $client);

            if ($result) {
                return $result;
            }

            return null;
        } catch (\Exception $e) {
            Log::error('ImageTranscriptionService: Erro ao analisar imagem com OpenAI Vision', [
                'error' => $e->getMessage(),
                'image_path' => $imagePath,
                'trace' => $e->getTraceAsString()
            ]);

            return null;
        }
    }

    /**
     * Analisa imagem a partir de dados base64 (útil para APIs)
     */
    public function analyzeFromBase64(string $base64Image, string $extension = 'jpg'): ?array
    {
        try {
            $imageData = base64_decode($base64Image);

            if (empty($imageData)) {
                Log::error('ImageTranscriptionService: Falha na decodificação base64 da imagem');
                return null;
            }

            // Criar arquivo temporário
            $tempPath = 'temp-images/' . uniqid('api_image_', true) . '.' . $extension;
            $success = Storage::disk('public')->put($tempPath, $imageData);

            if (!$success) {
                Log::error('ImageTranscriptionService: Falha ao salvar arquivo temporário de imagem');
                return null;
            }

            // Analisar
            $result = $this->analyzeImage($tempPath, 'public');

            // Limpar arquivo temporário
            Storage::disk('public')->delete($tempPath);

            return $result;
        } catch (\Exception $e) {
            Log::error('ImageTranscriptionService: Erro ao analisar imagem base64', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return null;
        }
    }

    /**
     * Analisa imagem a partir de um caminho absoluto
     */
    public function analyzeImageFromAbsolutePath(string $absolutePath): ?array
    {
        try {
            if (!file_exists($absolutePath)) {
                Log::error('ImageTranscriptionService: Arquivo de imagem não encontrado (caminho absoluto)', [
                    'full_path' => $absolutePath
                ]);
                return null;
            }

            // Validar arquivo
            if (!$this->validateImageFile($absolutePath)) {
                return null;
            }

            $client = $this->getOpenAiClient();
            if (!$client) {
                return null;
            }

            // Analisar com o modelo Vision
            $result = $this->analyzeWithVisionAPI($absolutePath, $client);

            if ($result) {
                return $result;
            }

            return null;
        } catch (\Exception $e) {
            Log::error('ImageTranscriptionService: Erro ao analisar imagem (caminho absoluto)', [
                'error' => $e->getMessage(),
                'image_path' => $absolutePath,
                'trace' => $e->getTraceAsString()
            ]);

            return null;
        }
    }

    /**
     * Analisa imagem a partir de um arquivo enviado (UploadedFile)
     */
    public function analyzeFromUploadedFile($uploadedFile): ?array
    {
        try {
            if (!$uploadedFile || !$uploadedFile->isValid()) {
                Log::warning('ImageTranscriptionService: Arquivo de imagem inválido para análise');
                return null;
            }

            // Validar tipo de arquivo
            if (!$this->validateUploadedImage($uploadedFile)) {
                return null;
            }

            // Salvar temporariamente
            $tempPath = 'temp-uploads/' . uniqid('upload_', true) . '.' . $uploadedFile->getClientOriginalExtension();
            $success = Storage::disk('public')->put($tempPath, file_get_contents($uploadedFile->getRealPath()));

            if (!$success) {
                Log::error('ImageTranscriptionService: Falha ao salvar arquivo temporário de upload');
                return null;
            }

            // Analisar
            $result = $this->analyzeImage($tempPath, 'public');

            // Limpar arquivo temporário
            Storage::disk('public')->delete($tempPath);

            return $result;
        } catch (\Exception $e) {
            Log::error('ImageTranscriptionService: Erro ao analisar imagem de upload', [
                'error' => $e->getMessage(),
                'file_name' => $uploadedFile ? $uploadedFile->getClientOriginalName() : 'unknown'
            ]);

            return null;
        }
    }

    /**
     * Valida arquivo de imagem para análise
     */
    private function validateImageFile(string $fullPath): bool
    {
        try {
            $fileSize = filesize($fullPath);

            if ($fileSize <= 0) {
                Log::error('ImageTranscriptionService: Arquivo de imagem inválido para análise', [
                    'file_size' => $fileSize
                ]);
                return false;
            }

            // Verificar se o arquivo não é muito grande (limite da OpenAI: 20MB)
            $maxSize = 20 * 1024 * 1024; // 20MB
            if ($fileSize > $maxSize) {
                Log::warning('ImageTranscriptionService: Arquivo de imagem muito grande para análise', [
                    'file_size_mb' => round($fileSize / (1024 * 1024), 2),
                    'max_size_mb' => round($maxSize / (1024 * 1024), 2)
                ]);
                return false;
            }

            // Verificar se é uma imagem válida
            $imageInfo = getimagesize($fullPath);
            if (!$imageInfo) {
                Log::warning('ImageTranscriptionService: Arquivo não é uma imagem válida', [
                    'full_path' => $fullPath
                ]);
                return false;
            }

            return true;
        } catch (\Exception $e) {
            Log::error('ImageTranscriptionService: Erro ao validar arquivo de imagem', [
                'error' => $e->getMessage(),
                'full_path' => $fullPath
            ]);
            return false;
        }
    }

    /**
     * Valida imagem enviada via upload
     */
    private function validateUploadedImage($uploadedFile): bool
    {
        try {
            $originalName = $uploadedFile->getClientOriginalName();
            $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
            $mimeType = $uploadedFile->getMimeType();

            // Verificar extensão
            $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];
            if (!in_array($extension, $allowedExtensions)) {
                Log::warning('ImageTranscriptionService: Extensão de imagem não suportada', [
                    'file_name' => $originalName,
                    'extension' => $extension,
                    'allowed_extensions' => $allowedExtensions
                ]);
                return false;
            }

            // Verificar MIME type
            $allowedMimeTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp'];
            if (!in_array($mimeType, $allowedMimeTypes)) {
                Log::warning('ImageTranscriptionService: MIME type de imagem não suportado', [
                    'file_name' => $originalName,
                    'mime_type' => $mimeType,
                    'allowed_mime_types' => $allowedMimeTypes
                ]);
                return false;
            }

            // Verificar tamanho
            $maxSize = 20 * 1024 * 1024; // 20MB
            $fileSize = $uploadedFile->getSize();

            if ($fileSize > $maxSize) {
                Log::warning('ImageTranscriptionService: Imagem muito grande para análise', [
                    'file_name' => $originalName,
                    'file_size_mb' => round($fileSize / (1024 * 1024), 2),
                    'max_size_mb' => round($maxSize / (1024 * 1024), 2)
                ]);
                return false;
            }

            return true;
        } catch (\Exception $e) {
            Log::error('ImageTranscriptionService: Erro ao validar imagem de upload', [
                'error' => $e->getMessage(),
                'file_name' => $uploadedFile ? $uploadedFile->getClientOriginalName() : 'unknown'
            ]);
            return false;
        }
    }

    /**
     * Cria cliente OpenAI para análise de imagem
     */
    private function getOpenAiClient()
    {
        try {
            $settings = Settings::first();

            if (!$settings) {
                Log::error('ImageTranscriptionService: Configurações não encontradas');
                return null;
            }

            if (empty($settings->api_key)) {
                Log::error('ImageTranscriptionService: Chave da OpenAI não configurada');
                return null;
            }

            $httpClient = new Client([
                'timeout' => 120,
                'connect_timeout' => 30,
                'verify' => config('services.http.verify_ssl', true)
            ]);

            $client = OpenAI::factory()
                ->withApiKey($settings->api_key)
                ->withHttpClient($httpClient)
                ->make();

            return $client;
        } catch (\Exception $e) {
            Log::error('ImageTranscriptionService: Erro ao criar cliente OpenAI', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return null;
        }
    }

    /**
     * Analisa imagem usando OpenAI Vision API
     */
    private function analyzeWithVisionAPI(string $imagePath, $client): ?array
    {
        try {
            // Converter imagem para base64
            $imageData = file_get_contents($imagePath);
            $base64Image = base64_encode($imageData);
            $mimeType = mime_content_type($imagePath);

            $dataUrl = "data:{$mimeType};base64,{$base64Image}";

            // Análise com OpenAI Vision
            $response = $client->chat()->create([
                'model' => AiModelCatalog::default(),
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'Você é um assistente de IA especialista em visão computacional e atendimento ao cliente via WhatsApp. Analise a imagem detalhadamente. Se contiver texto (OCR), transcreva-o com precisão. Se for comprovante de pagamento, extraia valor, data e beneficiário. Se for foto de produto, documento ou problema técnico, forneça uma descrição clara, objetiva e contextual para apoiar a conversa.'
                    ],
                    [
                        'role' => 'user',
                        'content' => [
                            [
                                'type' => 'text',
                                'text' => 'Descreva e analise detalhadamente esta imagem enviada pelo cliente:'
                            ],
                            [
                                'type' => 'image_url',
                                'image_url' => [
                                    'url' => $dataUrl
                                ]
                            ]
                        ]
                    ]
                ],
                'max_completion_tokens' => 1500
            ]);

            $analysis = (string)($response->choices[0]->message->content) ?? 'Não foi possível analisar a imagem.';

            // Construir resultado estruturado
            $result = [
                'analysis' => $analysis,
                'model_used' => AiModelCatalog::default(),
                'analysis_length' => strlen($analysis),
                'timestamp' => now()->toISOString()
            ];

            return $result;
        } catch (\Exception $e) {
            Log::error('ImageTranscriptionService: Erro na análise com Vision API', [
                'error' => $e->getMessage(),
                'image_path' => $imagePath
            ]);

            return null;
        }
    }

    /**
     * Analisa imagem e retorna contexto baseado na análise ou fallback
     */
    public function analyzeImageWithContext($imagePath, $disk = 'public'): array
    {
        try {
            if (!Storage::disk($disk)->exists($imagePath)) {
                Log::error('ImageTranscriptionService: Arquivo de imagem não encontrado', [
                    'image_path' => $imagePath,
                    'disk' => $disk
                ]);
                return [
                    'success' => false,
                    'error' => 'Arquivo não encontrado',
                    'context' => null
                ];
            }

            $fileSize = Storage::disk($disk)->size($imagePath);
            $fileSizeKB = round($fileSize / 1024, 2);

            // Tentar análise
            $analysis = $this->analyzeImage($imagePath, $disk);

            if ($analysis) {
                // Análise bem-sucedida
                $context = $this->buildAnalysisContext($analysis['analysis'], $fileSizeKB);

                return [
                    'success' => true,
                    'analysis' => $analysis['analysis'],
                    'context' => $analysis['context'],
                    'file_size_kb' => $fileSizeKB,
                    'model_used' => $analysis['model_used']
                ];
            } else {
                // Fallback para análise baseada em metadados
                $context = $this->buildFallbackImageContext($imagePath, $fileSizeKB);

                return [
                    'success' => false,
                    'analysis' => null,
                    'context' => $context,
                    'file_size_kb' => $fileSizeKB
                ];
            }
        } catch (\Exception $e) {
            Log::error('ImageTranscriptionService: Erro ao analisar imagem com contexto', [
                'error' => $e->getMessage(),
                'image_path' => $imagePath,
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'context' => 'A usuária enviou uma imagem. A análise não está disponível no momento.',
                'file_size_kb' => 0
            ];
        }
    }

    /**
     * Constrói contexto baseado na análise real da imagem
     */
    private function buildAnalysisContext(string $analysis, float $fileSizeKB): string
    {
        $description = "A usuária enviou uma imagem de {$fileSizeKB}KB.\n\n";

        $description .= "🖼️ **ANÁLISE DA IMAGEM:**\n";
        $description .= "\"" . $analysis . "\"\n\n";

        $description .= "🎭 **CONTEXTO VISUAL:**\n";
        $description .= "• A imagem foi analisada com sucesso pela IA\n";
        $description .= "• O conteúdo foi interpretado e descrito detalhadamente\n";
        $description .= "• Agora você pode responder baseado na análise visual\n\n";

        $description .= "💬 **INSTRUÇÕES PARA O AGENTE:**\n";
        $description .= "• Responda diretamente ao conteúdo da imagem analisada\n";
        $description .= "• Demonstre que entendeu o que foi mostrado\n";
        $description .= "• Ofereça insights ou comentários relevantes\n";
        $description .= "• Mantenha o tom empático e acolhedor\n";
        $description .= "• Use a análise para criar uma conversa natural";

        return $description;
    }

    /**
     * Constrói contexto de fallback quando a análise falha
     */
    private function buildFallbackImageContext(string $imagePath, float $fileSizeKB): string
    {
        $description = "A usuária enviou uma imagem de {$fileSizeKB}KB.\n\n";

        $description .= "⚠️ **ANÁLISE NÃO DISPONÍVEL:**\n";
        $description .= "• A análise automática não foi possível\n";
        $description .= "• Isso pode acontecer por problemas técnicos\n";
        $description .= "• Ou a imagem pode estar em formato não suportado\n\n";

        $description .= "🎭 **CONTEXTO VISUAL:**\n";
        $description .= "• Ela preferiu enviar uma imagem ao invés de texto\n";
        $description .= "• O conteúdo visual pode ser importante para ela\n";
        $description .= "• Pode conter detalhes pessoais ou situações complexas\n\n";

        $description .= "💬 **INSTRUÇÕES PARA O AGENTE:**\n";
        $description .= "• Reconheça que ela escolheu enviar uma imagem\n";
        $description .= "• Pergunte sobre o conteúdo da imagem de forma empática\n";
        $description .= "• Ofereça apoio se necessário\n";
        $description .= "• Mantenha o tom acolhedor e compreensivo\n";
        $description .= "• Sugira que ela descreva a imagem se necessário";

        return $description;
    }

    /**
     * Obtém informações básicas da imagem sem análise de IA
     */
    public function getImageMetadata($imagePath, $disk = 'public'): ?array
    {
        try {
            if (!Storage::disk($disk)->exists($imagePath)) {
                return null;
            }

            $fullPath = storage_path('app/' . $disk . '/' . $imagePath);
            $imageInfo = getimagesize($fullPath);

            if (!$imageInfo) {
                return null;
            }

            return [
                'width' => $imageInfo[0],
                'height' => $imageInfo[1],
                'type' => $imageInfo[2],
                'mime' => $imageInfo['mime'],
                'file_size' => Storage::disk($disk)->size($imagePath),
                'dimensions' => $imageInfo[0] . 'x' . $imageInfo[1]
            ];
        } catch (\Exception $e) {
            Log::error('ImageTranscriptionService: Erro ao obter metadados da imagem', [
                'error' => $e->getMessage(),
                'image_path' => $imagePath
            ]);

            return null;
        }
    }
}
