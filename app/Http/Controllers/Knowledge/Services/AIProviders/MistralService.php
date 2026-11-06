<?php

namespace App\Http\Controllers\Knowledge\Services\AIProviders;

use App\Models\Agents;
use App\Models\Settings;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use GuzzleHttp\Client;

/**
 * Serviço para integração com Mistral AI API.
 */
class MistralService implements AIProviderInterface
{
    /**
     * Capacidades do provedor Mistral AI
     */
    private const CAPABILITIES = [
        'text' => true,
        'audio' => false, // Mistral não suporta áudio
        'images' => false, // Mistral não suporta imagens
        'documents' => false, // Mistral não suporta documentos
        'streaming' => true,
        'function_calling' => true,
        'max_tokens' => 32768,
        'provider' => 'mistral'
    ];
    /**
     * Obtém cliente HTTP configurado para Mistral
     */
    private function getClient(Agents $agent)
    {
        $user = User::where('uuid', $agent->user_id)->first();

        if (!$user) {
            throw new \Exception('Usuário não encontrado.');
        }

        $userApi = $user->getApiForPlatform('mistral');

        if (!$userApi || empty($userApi->api_key)) {
            throw new \Exception('Usuário sem chave de API configurada para Mistral.');
        }

        return new Client([
            'base_uri' => 'https://api.mistral.ai/v1/',
            'headers' => [
                'Authorization' => 'Bearer ' . $userApi->api_key,
                'Content-Type' => 'application/json',
            ],
            'timeout' => 120,
            'connect_timeout' => 30,
            'verify' => config('services.http.verify_ssl', true)
        ]);
    }

    /**
     * Gera resposta usando Mistral API.
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

            // Configurações padrão para Mistral
            $defaultOptions = [
                'model' => $agent->model ?? 'mistral-medium',
                'messages' => $messages,
                'max_tokens' => $agent->max_characters ? min($agent->max_characters, 2048) : ($settings->max_characters ?? 500),
                'temperature' => $options['temperature'] ?? 0.7,
                'top_p' => 0.95,
                'random_seed' => null,
            ];

            $params = array_merge($defaultOptions, $options);

            $response = $client->post('chat/completions', [
                'json' => $params
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (!isset($data['choices'][0]['message']['content'])) {
                throw new \Exception('Resposta inválida da API Mistral');
            }

            $message = trim($data['choices'][0]['message']['content']);
            $usage = $data['usage'] ?? [];

            $promptTokens = $usage['prompt_tokens'] ?? 0;
            $completionTokens = $usage['completion_tokens'] ?? 0;
            $totalTokens = $usage['total_tokens'] ?? ($promptTokens + $completionTokens);

            return [
                'message' => $message,
                'tokens' => $totalTokens,
                'prompt_tokens' => $promptTokens,
                'completion_tokens' => $completionTokens,
                'model' => $params['model']
            ];
        } catch (\Exception $e) {
            Log::error("Erro na Mistral AI API: {$e->getMessage()}", [
                'agent_id' => $agent->uuid,
                'error' => $e->getMessage()
            ]);

            return [
                'message' => 'Desculpe, não consegui processar sua mensagem. Tente novamente.',
                'tokens' => 0,
                'model' => $agent->model ?? 'mistral-medium',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Mistral não suporta transcrição de áudio
     */
    public function transcribeAudio(string $audioPathOrUrl, Agents $agent): ?string
    {
        Log::info('Mistral AI não suporta transcrição de áudio', [
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
     * Retorna lista de modelos disponíveis do Mistral AI
     */
    public function getModels(): array
    {
        return [
            'mistral-large-latest' => 'Mistral Large',
            'mistral-medium-latest' => 'Mistral Medium',
            'mistral-small-latest' => 'Mistral Small',
            'mixtral-8x7b' => 'Mixtral 8x7B',
            'mixtral-8x22b' => 'Mixtral 8x22B',
        ];
    }

    /**
     * Valida se um modelo é suportado pelo Mistral AI
     */
    public function validateModel(string $model): bool
    {
        $supportedModels = array_keys($this->getModels());
        $modelLower = strtolower($model);

        // Verifica correspondência exata
        if (in_array($modelLower, array_map('strtolower', $supportedModels))) {
            return true;
        }

        // Verifica padrões Mistral
        if (str_contains($modelLower, 'mistral') || str_contains($modelLower, 'mixtral')) {
            return true;
        }

        return false;
    }
}
