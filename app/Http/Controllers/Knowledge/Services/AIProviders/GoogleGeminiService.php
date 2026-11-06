<?php

namespace App\Http\Controllers\Knowledge\Services\AIProviders;

use App\Models\Agents;
use App\Models\Settings;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use GuzzleHttp\Client;

/**
 * Serviço para integração com Google Gemini API.
 */
class GoogleGeminiService implements AIProviderInterface
{
    /**
     * Capacidades do provedor Google Gemini
     */
    private const CAPABILITIES = [
        'text' => true,
        'audio' => false, // Gemini não suporta transcrição de áudio pura
        'images' => true, // Gemini suporta análise de imagens (multimodal)
        'documents' => false, // Gemini não suporta documentos diretamente
        'streaming' => true,
        'function_calling' => true,
        'max_tokens' => 32768, // Gemini 2.0
        'provider' => 'gemini'
    ];
    /**
     * Obtém cliente HTTP configurado para Google Gemini
     */
    private function getClient(Agents $agent)
    {
        $user = User::where('uuid', $agent->user_id)->first();

        if (!$user) {
            throw new \Exception('Usuário não encontrado.');
        }

        $userApi = $user->getApiForPlatform('gemini');

        if (!$userApi || empty($userApi->api_key)) {
            throw new \Exception('Usuário sem chave de API configurada para Gemini.');
        }

        return new Client([
            'base_uri' => 'https://generativelanguage.googleapis.com/v1beta/',
            'timeout' => 120,
            'connect_timeout' => 30,
            'verify' => config('services.http.verify_ssl', true)
        ]);
    }

    /**
     * Normaliza nomes de modelos Gemini
     */
    private function normalizeModelName(?string $model): string
    {
        if (empty($model)) {
            return 'gemini-1.5-flash';
        }

        $modelMap = [
            'gemini-pro' => 'gemini-1.5-flash',
            'gemini-1.0-pro' => 'gemini-1.5-flash',
            'gemini-1.5-flash' => 'gemini-1.5-flash',
            'gemini-1.5-pro' => 'gemini-1.5-pro',
            'gemini-2.0-flash' => 'gemini-2.0-flash',
            'gemini-2.0-flash-lite' => 'gemini-2.0-flash-lite',
            'gemini-flash' => 'gemini-1.5-flash',
        ];

        $lower = strtolower(trim($model));
        return $modelMap[$lower] ?? $model;
    }

    /**
     * Converte mensagens do formato OpenAI para Gemini
     * ✅ Suporta mensagens multimodais (texto + imagens)
     */
    private function convertMessagesToGemini(array $messages): array
    {
        $geminiContents = [];

        foreach ($messages as $message) {
            $role = $message['role'];
            $content = $message['content'];

            // Gemini usa 'user' e 'model', não 'system' ou 'assistant'
            $geminiRole = match ($role) {
                'system' => 'user', // Sistema vai como user
                'assistant' => 'model',
                'user' => 'user',
                default => 'user'
            };

            $parts = [];

            // ✅ Detectar se content é array (formato multimodal OpenAI)
            if (is_array($content)) {
                foreach ($content as $item) {
                    if (is_array($item)) {
                        $type = $item['type'] ?? null;

                        if ($type === 'text' && isset($item['text'])) {
                            // Texto
                            $parts[] = ['text' => $item['text']];
                        } elseif ($type === 'image_url' && isset($item['image_url']['url'])) {
                            // Imagem em formato data URL (data:image/png;base64,...)
                            $imageUrl = $item['image_url']['url'];

                            // Extrair base64 e mime type do data URL
                            if (preg_match('/^data:([^;]+);base64,(.+)$/', $imageUrl, $matches)) {
                                $mimeType = $matches[1];
                                $base64Data = $matches[2];

                                $parts[] = [
                                    'inline_data' => [
                                        'mime_type' => $mimeType,
                                        'data' => $base64Data
                                    ]
                                ];
                            } else {
                                Log::warning('Formato de imagem inválido para Gemini', [
                                    'image_url' => substr($imageUrl, 0, 100)
                                ]);
                            }
                        }
                    } elseif (is_string($item)) {
                        // Fallback: string direta
                        $parts[] = ['text' => $item];
                    }
                }
            } else {
                // Formato tradicional: content é string
                $parts[] = ['text' => (string)$content];
            }

            // Se não há parts válidas, pular mensagem
            if (empty($parts)) {
                continue;
            }

            $geminiContents[] = [
                'role' => $geminiRole,
                'parts' => $parts
            ];
        }

        return $geminiContents;
    }

    /**
     * Gera resposta usando Gemini API.
     *
     * @param array $messages Array de mensagens no formato ['role' => '...', 'content' => '...']
     * @param Agents $agent Agente configurado
     * @param array $options Opções adicionais (max_tokens, temperature, etc)
     * @return array ['message' => string, 'tokens' => int]
     */
    public function getCompletion(array $messages, Agents $agent, array $options = []): array
    {
        try {
            $client = $this->getClient($agent);
            $user = User::where('uuid', $agent->user_id)->first();
            $settings = Settings::first();

            $geminiContents = $this->convertMessagesToGemini($messages);
            $model = $this->normalizeModelName($agent->model ?? 'gemini-1.5-flash');

            // Obter a chave da API (já validada pelo getClient)
            $userApi = $user->getApiForPlatform('gemini');

            $defaultOptions = [
                'contents' => $geminiContents,
                'generationConfig' => [
                    'temperature' => $options['temperature'] ?? 0.7,
                    'maxOutputTokens' => $agent->max_characters ? min($agent->max_characters, 2048) : ($settings->max_characters ?? 500),
                ]
            ];

            $params = array_merge($defaultOptions, $options);

            $response = $client->post("models/{$model}:generateContent?key={$userApi->api_key}", [
                'json' => $params
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            // Verificar se existe resposta válida
            if (!isset($data['candidates']) || !isset($data['candidates'][0]['content']['parts'][0]['text'])) {
                Log::error('Resposta inválida da API Google Gemini', [
                    'agent_id' => $agent->uuid,
                    'model' => $model,
                    'response_data' => $data
                ]);
                throw new \Exception('Resposta inválida da API Google Gemini');
            }

            $message = trim($data['candidates'][0]['content']['parts'][0]['text']);

            // Contagem de tokens (Gemini fornece em usageMetadata)
            $usage = $data['usageMetadata'] ?? [];
            $totalTokens = $usage['totalTokenCount'] ?? 0;
            $promptTokens = $usage['promptTokenCount'] ?? 0;
            $completionTokens = $usage['candidatesTokenCount'] ?? 0;

            return [
                'message' => $message,
                'tokens' => $totalTokens,
                'prompt_tokens' => $promptTokens,
                'completion_tokens' => $completionTokens,
                'model' => $model
            ];
        } catch (\Exception $e) {
            Log::error("Erro na Google Gemini API: {$e->getMessage()}", [
                'agent_id' => $agent->uuid,
                'error' => $e->getMessage()
            ]);

            return [
                'message' => 'Desculpe, não consegui processar sua mensagem. Tente novamente.',
                'tokens' => 0,
                'model' => $agent->model ?? 'gemini-pro',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Gemini suporta multimodal, mas não transcrição de áudio pura
     */
    public function transcribeAudio(string $audioPathOrUrl, Agents $agent): ?string
    {
        Log::info('Google Gemini não suporta transcrição de áudio pura (apenas multimodal)', [
            'agent_id' => $agent->uuid
        ]);

        return null;
    }

    /**
     * Verifica se o provedor suporta processamento de texto
     */
    public function supportsText(): bool
    {
        return self::CAPABILITIES['text'];
    }

    /**
     * Verifica se o provedor suporta transcrição de áudio
     */
    public function supportsAudio(): bool
    {
        return self::CAPABILITIES['audio'];
    }

    /**
     * Verifica se o provedor suporta análise de imagens
     */
    public function supportsImages(): bool
    {
        return self::CAPABILITIES['images'];
    }

    /**
     * Verifica se o provedor suporta processamento de documentos
     */
    public function supportsDocuments(): bool
    {
        return self::CAPABILITIES['documents'];
    }

    /**
     * Retorna capacidades do provedor em formato estruturado
     */
    public function getCapabilities(): array
    {
        return self::CAPABILITIES;
    }

    /**
     * Retorna lista de modelos disponíveis do Google Gemini
     */
    public function getModels(): array
    {
        return [
            'gemini-2.5-flash' => 'Gemini 2.5 Flash',
            'gemini-2.5-pro' => 'Gemini 2.5 Pro',
            'gemini-2.0-flash' => 'Gemini 2.0 Flash',
            'gemini-2.0-flash-lite' => 'Gemini 2.0 Flash Lite',
            'gemini-1.5-pro' => 'Gemini 1.5 Pro',
            'gemini-1.5-flash' => 'Gemini 1.5 Flash',
            'gemini-pro' => 'Gemini Pro',
        ];
    }

    /**
     * Valida se um modelo é suportado pelo Google Gemini
     */
    public function validateModel(string $model): bool
    {
        $supportedModels = array_keys($this->getModels());
        $modelLower = strtolower($model);

        // Verifica correspondência exata
        if (in_array($modelLower, array_map('strtolower', $supportedModels))) {
            return true;
        }

        // Verifica padrões Gemini
        if (str_contains($modelLower, 'gemini')) {
            return true;
        }

        return false;
    }
}
