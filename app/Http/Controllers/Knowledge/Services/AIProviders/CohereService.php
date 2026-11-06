<?php

namespace App\Http\Controllers\Knowledge\Services\AIProviders;

use App\Models\Agents;
use App\Models\Settings;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use GuzzleHttp\Client;

/**
 * Serviço para integração com Cohere API.
 */
class CohereService implements AIProviderInterface
{
    /**
     * Capacidades do provedor Cohere
     */
    private const CAPABILITIES = [
        'text' => true,
        'audio' => false, // Cohere não suporta áudio
        'images' => false, // Cohere não suporta imagens
        'documents' => false, // Cohere não suporta documentos diretamente
        'streaming' => true,
        'function_calling' => false,
        'max_tokens' => 4096,
        'provider' => 'cohere'
    ];
    /**
     * Obtém cliente HTTP configurado para Cohere
     */
    private function getClient(Agents $agent)
    {
        $user = User::where('uuid', $agent->user_id)->first();

        if (!$user) {
            throw new \Exception('Usuário não encontrado.');
        }

        $userApi = $user->getApiForPlatform('cohere');

        if (!$userApi || empty($userApi->api_key)) {
            throw new \Exception('Usuário sem chave de API configurada para Cohere.');
        }

        return new Client([
            'base_uri' => 'https://api.cohere.ai/v1/',
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
     * Converte mensagens do formato OpenAI para Cohere
     */
    private function convertMessagesToCohere(array $messages): array
    {
        $chatHistory = [];
        $message = '';

        foreach ($messages as $msg) {
            $role = $msg['role'];
            $content = $msg['content'];

            if ($role === 'system') {
                // Sistema pode ser tratado como contexto adicional
                continue;
            }

            $chatHistory[] = [
                'role' => $role === 'assistant' ? 'CHATBOT' : 'USER',
                'message' => $content
            ];
        }

        // Última mensagem do usuário
        if (!empty($chatHistory)) {
            $lastMessage = array_pop($chatHistory);
            if ($lastMessage['role'] === 'USER') {
                $message = $lastMessage['message'];
            }
        }

        return [
            'chat_history' => $chatHistory,
            'message' => $message
        ];
    }

    /**
     * Gera resposta usando Cohere API.
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

            $converted = $this->convertMessagesToCohere($messages);

            // Configurações padrão para Cohere
            $defaultOptions = [
                'model' => $agent->model ?? 'command',
                'message' => $converted['message'],
                'chat_history' => $converted['chat_history'],
                'max_tokens' => $agent->max_characters ? min($agent->max_characters, 2048) : ($settings->max_characters ?? 500),
                'temperature' => $options['temperature'] ?? 0.7,
                'p' => 0.75,
                'k' => 0,
            ];

            $params = array_merge($defaultOptions, $options);

            $response = $client->post('chat', [
                'json' => $params
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (!isset($data['text'])) {
                throw new \Exception('Resposta inválida da API Cohere');
            }

            $message = trim($data['text']);

            // Cohere fornece informações de token
            $meta = $data['meta'] ?? [];
            $tokens = $meta['tokens'] ?? [];

            $inputTokens = $tokens['input_tokens'] ?? 0;
            $outputTokens = $tokens['output_tokens'] ?? 0;
            $totalTokens = $inputTokens + $outputTokens;

            return [
                'message' => $message,
                'tokens' => $totalTokens,
                'prompt_tokens' => $inputTokens,
                'completion_tokens' => $outputTokens,
                'model' => $params['model']
            ];
        } catch (\Exception $e) {
            Log::error("Erro na Cohere API: {$e->getMessage()}", [
                'agent_id' => $agent->uuid,
                'error' => $e->getMessage()
            ]);

            return [
                'message' => 'Desculpe, não consegui processar sua mensagem. Tente novamente.',
                'tokens' => 0,
                'model' => $agent->model ?? 'command',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Cohere não suporta transcrição de áudio
     */
    public function transcribeAudio(string $audioPathOrUrl, Agents $agent): ?string
    {
        Log::info('Cohere não suporta transcrição de áudio', [
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
     * Retorna lista de modelos disponíveis do Cohere
     */
    public function getModels(): array
    {
        return [
            'command-r' => 'Command R',
            'command-r-plus' => 'Command R+',
            'command' => 'Command',
            'command-light' => 'Command Light',
        ];
    }

    /**
     * Valida se um modelo é suportado pelo Cohere
     */
    public function validateModel(string $model): bool
    {
        $supportedModels = array_keys($this->getModels());
        $modelLower = strtolower($model);

        // Verifica correspondência exata
        if (in_array($modelLower, array_map('strtolower', $supportedModels))) {
            return true;
        }

        // Verifica padrões Cohere
        if (str_contains($modelLower, 'command') || str_contains($modelLower, 'cohere')) {
            return true;
        }

        return false;
    }
}
