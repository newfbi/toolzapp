<?php

namespace App\Http\Controllers\Knowledge\Services\AIProviders;

use App\Models\Agents;
use App\Models\Settings;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use GuzzleHttp\Client;

/**
 * Serviço para integração com Anthropic Claude API.
 */
class AnthropicClaudeService implements AIProviderInterface
{
    /**
     * Capacidades do provedor Anthropic Claude
     */
    private const CAPABILITIES = [
        'text' => true,
        'audio' => false, // Claude não suporta áudio
        'images' => true, // Claude suporta análise de imagens
        'documents' => false, // Claude não suporta documentos diretamente
        'streaming' => true,
        'function_calling' => true,
        'max_tokens' => 200000, // Claude 3.5 Sonnet
        'provider' => 'claude'
    ];
    /**
     * Obtém cliente HTTP configurado para Anthropic
     */
    private function getClient(Agents $agent)
    {
        $user = User::where('uuid', $agent->user_id)->first();

        if (!$user) {
            throw new \Exception('Usuário não encontrado.');
        }

        $userApi = $user->getApiForPlatform('claude');

        if (!$userApi || empty($userApi->api_key)) {
            throw new \Exception('Usuário sem chave de API configurada para Claude.');
        }

        return new Client([
            'base_uri' => 'https://api.anthropic.com/v1/',
            'headers' => [
                'x-api-key' => $userApi->api_key,
                'anthropic-version' => '2023-06-01',
                'Content-Type' => 'application/json',
            ],
            'timeout' => 120,
            'connect_timeout' => 30,
            'verify' => config('services.http.verify_ssl', true)
        ]);
    }

    /**
     * Converte mensagens do formato OpenAI para Anthropic
     * ✅ Suporta mensagens multimodais (texto + imagens)
     */
    private function convertMessagesToAnthropic(array $messages): array
    {
        $anthropicMessages = [];

        foreach ($messages as $message) {
            $role = $message['role'];
            $content = $message['content'];

            // Anthropic usa 'user' e 'assistant', não 'system'
            if ($role === 'system') {
                // Sistema vai para o system prompt (extraído separadamente)
                continue;
            }

            $anthropicContent = [];

            // ✅ Detectar se content é array (formato multimodal OpenAI)
            if (is_array($content)) {
                foreach ($content as $item) {
                    if (is_array($item)) {
                        $type = $item['type'] ?? null;

                        if ($type === 'text' && isset($item['text'])) {
                            // Texto
                            $anthropicContent[] = [
                                'type' => 'text',
                                'text' => $item['text']
                            ];
                        } elseif ($type === 'image_url' && isset($item['image_url']['url'])) {
                            // Imagem em formato data URL (data:image/png;base64,...)
                            $imageUrl = $item['image_url']['url'];

                            // Extrair base64 e mime type do data URL
                            if (preg_match('/^data:([^;]+);base64,(.+)$/', $imageUrl, $matches)) {
                                $mimeType = $matches[1];
                                $base64Data = $matches[2];

                                // Claude espera media_type (sem "image/" prefix em algumas versões, mas geralmente aceita)
                                $mediaType = $mimeType; // Claude aceita mime types completos como "image/png", "image/jpeg", etc.

                                $anthropicContent[] = [
                                    'type' => 'image',
                                    'source' => [
                                        'type' => 'base64',
                                        'media_type' => $mediaType,
                                        'data' => $base64Data
                                    ]
                                ];
                            } else {
                                Log::warning('Formato de imagem inválido para Claude', [
                                    'image_url' => substr($imageUrl, 0, 100)
                                ]);
                            }
                        }
                    } elseif (is_string($item)) {
                        // Fallback: string direta
                        $anthropicContent[] = [
                            'type' => 'text',
                            'text' => $item
                        ];
                    }
                }
            } else {
                // Formato tradicional: content é string
                $anthropicContent = (string)$content;
            }

            // Se não há content válido, pular mensagem
            if (empty($anthropicContent)) {
                continue;
            }

            $anthropicMessages[] = [
                'role' => $role === 'user' ? 'user' : 'assistant',
                'content' => $anthropicContent
            ];
        }

        return $anthropicMessages;
    }

    /**
     * Extrai system prompt das mensagens
     * ✅ Suporta content como string (formato tradicional)
     */
    private function extractSystemPrompt(array $messages): string
    {
        foreach ($messages as $message) {
            if ($message['role'] === 'system') {
                $content = $message['content'];
                // Se content é array (multimodal), extrair apenas texto
                if (is_array($content)) {
                    $textParts = [];
                    foreach ($content as $item) {
                        if (is_array($item) && ($item['type'] ?? null) === 'text' && isset($item['text'])) {
                            $textParts[] = $item['text'];
                        } elseif (is_string($item)) {
                            $textParts[] = $item;
                        }
                    }
                    return implode(' ', $textParts);
                }
                return (string)$content;
            }
        }
        return '';
    }

    /**
     * Gera resposta usando Claude API.
     *
     * @param array $messages Array de mensagens no formato ['role' => '...', 'content' => '...']
     * @param Agents $agent Agente configurado
     * @param array $options Opções adicionais (max_tokens, temperature, etc)
     * @return array ['message' => string, 'tokens' => int]
     */
    /**
     * Normaliza nomes de modelos legados para versões mais recentes do Claude
     */
    private function normalizeModelName(?string $model): string
    {
        if (empty($model)) {
            return 'claude-3-5-sonnet-latest';
        }

        $modelMap = [
            'claude-3-sonnet-20240229' => 'claude-3-5-sonnet-latest',
            'claude-3-sonnet' => 'claude-3-5-sonnet-latest',
            'claude-3-5-sonnet' => 'claude-3-5-sonnet-latest',
            'claude-3-opus-20240229' => 'claude-3-opus-latest',
            'claude-3-opus' => 'claude-3-opus-latest',
            'claude-3-haiku-20240307' => 'claude-3-5-haiku-latest',
            'claude-3-haiku' => 'claude-3-5-haiku-latest',
            'claude-3-5-haiku' => 'claude-3-5-haiku-latest',
        ];

        $lower = strtolower(trim($model));
        return $modelMap[$lower] ?? $model;
    }

    /**
     * Gera resposta usando Anthropic Messages API.
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
            $settings = Settings::first();
            $targetModel = $this->normalizeModelName($agent->model ?? 'claude-3-5-sonnet-latest');

            $systemPrompt = $this->extractSystemPrompt($messages);
            $anthropicMessages = $this->convertMessagesToAnthropic($messages);

            // Configurações padrão para Claude
            $defaultOptions = [
                'model' => $targetModel,
                'max_tokens' => $agent->max_characters ? min($agent->max_characters, 4000) : ($settings->max_characters ?? 600),
                'temperature' => $options['temperature'] ?? 0.7,
                'messages' => $anthropicMessages,
            ];

            if (!empty($systemPrompt)) {
                $defaultOptions['system'] = $systemPrompt;
            }

            $params = array_merge($defaultOptions, $options);

            $response = $client->post('messages', [
                'json' => $params
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (!isset($data['content'][0]['text'])) {
                throw new \Exception('Resposta inválida da API Anthropic');
            }

            $message = trim($data['content'][0]['text']);
            $usage = $data['usage'] ?? [];

            $inputTokens = $usage['input_tokens'] ?? 0;
            $outputTokens = $usage['output_tokens'] ?? 0;
            $totalTokens = $inputTokens + $outputTokens;

            return [
                'message' => $message,
                'tokens' => $totalTokens,
                'prompt_tokens' => $inputTokens,
                'completion_tokens' => $outputTokens,
                'model' => $params['model']
            ];
        } catch (\Exception $e) {
            Log::error("Erro na Anthropic Claude API: {$e->getMessage()}", [
                'agent_id' => $agent->uuid,
                'error' => $e->getMessage()
            ]);

            return [
                'message' => 'Desculpe, não consegui processar sua mensagem. Tente novamente.',
                'tokens' => 0,
                'model' => $agent->model ?? 'claude-3-5-sonnet-latest',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Claude não suporta transcrição de áudio nativamente
     */
    public function transcribeAudio(string $audioPathOrUrl, Agents $agent): ?string
    {
        Log::info('Anthropic Claude não suporta transcrição de áudio', [
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
     * Retorna lista de modelos disponíveis do Anthropic Claude
     */
    public function getModels(): array
    {
        return [
            'claude-3-5-sonnet-20241022' => 'Claude 3.5 Sonnet',
            'claude-3-5-haiku-20241022' => 'Claude 3.5 Haiku',
            'claude-3-opus-20240229' => 'Claude 3 Opus',
            'claude-3-sonnet-20240229' => 'Claude 3 Sonnet',
            'claude-3-haiku-20240307' => 'Claude 3 Haiku',
        ];
    }

    /**
     * Valida se um modelo é suportado pelo Anthropic Claude
     */
    public function validateModel(string $model): bool
    {
        $supportedModels = array_keys($this->getModels());
        $modelLower = strtolower($model);

        // Verifica correspondência exata
        if (in_array($modelLower, array_map('strtolower', $supportedModels))) {
            return true;
        }

        // Verifica padrões Claude
        if (str_contains($modelLower, 'claude')) {
            return true;
        }

        return false;
    }
}
