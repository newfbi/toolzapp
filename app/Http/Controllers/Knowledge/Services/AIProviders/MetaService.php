<?php

namespace App\Http\Controllers\Knowledge\Services\AIProviders;

use App\Models\Agents;
use App\Models\Settings;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use GuzzleHttp\Client;

/**
 * Serviço para integração com Meta Llama através de provedores terceiros.
 * Usa Replicate ou outros provedores compatíveis.
 */
class MetaService implements AIProviderInterface
{
    /**
     * Capacidades do provedor Meta Llama
     */
    private const CAPABILITIES = [
        'text' => true,
        'audio' => false, // Meta Llama não suporta áudio
        'images' => false, // Meta Llama não suporta imagens
        'documents' => false, // Meta Llama não suporta documentos
        'streaming' => false, // Replicate não suporta streaming direto
        'function_calling' => false,
        'max_tokens' => 4096,
        'provider' => 'meta'
    ];
    /**
     * Obtém cliente HTTP configurado para Meta (através de provedores)
     */
    private function getClient(Agents $agent)
    {
        $user = User::where('uuid', $agent->user_id)->first();

        if (!$user) {
            throw new \Exception('Usuário não encontrado.');
        }

        $userApi = $user->getApiForPlatform('meta');

        if (!$userApi || empty($userApi->api_key)) {
            throw new \Exception('Usuário sem chave de API configurada para Meta.');
        }

        // Por padrão, usa Replicate para Meta Llama
        return new Client([
            'base_uri' => 'https://api.replicate.com/v1/',
            'headers' => [
                'Authorization' => 'Token ' . $userApi->api_key,
                'Content-Type' => 'application/json',
            ],
            'timeout' => 120,
            'connect_timeout' => 30,
            'verify' => config('services.http.verify_ssl', true)
        ]);
    }

    /**
     * Converte mensagens para o formato Replicate/Llama
     */
    private function convertMessagesToLlama(array $messages): string
    {
        $conversation = '';

        foreach ($messages as $message) {
            $role = $message['role'];
            $content = $message['content'];

            if ($role === 'system') {
                $conversation .= "<s>[INST] <<SYS>>\n{$content}\n<</SYS>>\n\n";
            } elseif ($role === 'user') {
                if (empty($conversation)) {
                    $conversation .= "<s>[INST] {$content} [/INST]";
                } else {
                    $conversation .= "{$content} [/INST]";
                }
            } elseif ($role === 'assistant') {
                $conversation .= " {$content} </s>";
            }
        }

        return $conversation;
    }

    /**
     * Gera resposta usando Meta Llama API (via Replicate).
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

            $model = $agent->model ?? 'meta/llama-2-70b-chat';
            $prompt = $this->convertMessagesToLlama($messages);

            // Configurações padrão para Llama via Replicate
            $defaultOptions = [
                'input' => [
                    'prompt' => $prompt,
                    'max_tokens' => $agent->max_characters ? min($agent->max_characters, 2048) : ($settings->max_characters ?? 500),
                    'temperature' => $options['temperature'] ?? 0.7,
                    'top_p' => 0.9,
                    'top_k' => 50,
                    'repetition_penalty' => 1.0,
                ]
            ];

            $params = array_merge($defaultOptions, $options);

            $response = $client->post("predictions", [
                'json' => [
                    'version' => $this->getModelVersion($model),
                    'input' => $params['input']
                ]
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (!isset($data['urls']['get'])) {
                throw new \Exception('Resposta inválida da API Replicate');
            }

            // Replicate é assíncrono, então precisamos fazer polling
            $resultUrl = $data['urls']['get'];
            $maxAttempts = 30; // 30 segundos máximo
            $attempt = 0;

            do {
                sleep(1); // Espera 1 segundo
                $attempt++;

                $resultResponse = $client->get($resultUrl);
                $resultData = json_decode($resultResponse->getBody()->getContents(), true);

                if (isset($resultData['status']) && $resultData['status'] === 'succeeded') {
                    break;
                }

                if (isset($resultData['status']) && in_array($resultData['status'], ['failed', 'cancelled'])) {
                    throw new \Exception('Predição falhou no Replicate');
                }
            } while ($attempt < $maxAttempts);

            if (!isset($resultData['output'])) {
                throw new \Exception('Timeout na resposta do Replicate');
            }

            $message = trim(is_array($resultData['output']) ? implode('', $resultData['output']) : $resultData['output']);

            // Estimativa de tokens (Replicate não fornece contagem exata)
            $totalTokens = $this->estimateTokens($prompt . $message);
            $promptTokens = $this->estimateTokens($prompt);
            $completionTokens = $totalTokens - $promptTokens;

            return [
                'message' => $message,
                'tokens' => $totalTokens,
                'prompt_tokens' => $promptTokens,
                'completion_tokens' => $completionTokens,
                'model' => $model
            ];
        } catch (\Exception $e) {
            Log::error("Erro na Meta Llama API: {$e->getMessage()}", [
                'agent_id' => $agent->uuid,
                'error' => $e->getMessage()
            ]);

            return [
                'message' => 'Desculpe, não consegui processar sua mensagem. Tente novamente.',
                'tokens' => 0,
                'model' => $agent->model ?? 'meta/llama-2-70b-chat',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Mapeia modelos para versões do Replicate
     */
    private function getModelVersion(string $model): string
    {
        $versions = [
            'meta/llama-2-70b-chat' => '02e509c789964a7ea8736978a43525956ef40397be9033abf9fd2badfe68c9e3f',
            'meta/llama-2-13b-chat' => 'f4e2de70d66816a838a89eeeb621910adffb0dd0baba3976c96980970978018c5',
            'meta/llama-2-7b-chat' => '13c3cdee13ee059ab779f0291d29054dab00a47dad858b067d908f2f93d5fca2',
        ];

        return $versions[$model] ?? $versions['meta/llama-2-70b-chat'];
    }

    /**
     * Estimativa simples de tokens
     */
    private function estimateTokens(string $text): int
    {
        // Estimativa aproximada: 1 token ≈ 4 caracteres para Llama
        return (int) ceil(strlen($text) / 4);
    }

    /**
     * Meta Llama não suporta transcrição de áudio
     */
    public function transcribeAudio(string $audioPathOrUrl, Agents $agent): ?string
    {
        Log::info('Meta Llama não suporta transcrição de áudio', [
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
     * Retorna lista de modelos disponíveis do Meta Llama
     */
    public function getModels(): array
    {
        return [
            'meta/llama-2-70b-chat' => 'Llama 2 70B Chat',
            'meta/llama-2-13b-chat' => 'Llama 2 13B Chat',
            'meta/llama-2-7b-chat' => 'Llama 2 7B Chat',
            'llama-3-70b' => 'Llama 3 70B',
            'llama-3-8b' => 'Llama 3 8B',
        ];
    }

    /**
     * Valida se um modelo é suportado pelo Meta Llama
     */
    public function validateModel(string $model): bool
    {
        $supportedModels = array_keys($this->getModels());
        $modelLower = strtolower($model);

        // Verifica correspondência exata
        if (in_array($modelLower, array_map('strtolower', $supportedModels))) {
            return true;
        }

        // Verifica padrões Llama
        if (str_contains($modelLower, 'llama') || str_contains($modelLower, 'meta')) {
            return true;
        }

        return false;
    }
}
