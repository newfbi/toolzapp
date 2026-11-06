<?php

namespace App\Http\Controllers\Knowledge\Services\AIProviders;

use App\Models\Agents;
use App\Models\Settings;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use GuzzleHttp\Client;
use OpenAI;

/**
 * Serviço para integração com Fireworks AI API.
 * Fireworks AI é compatível com OpenAI API, então usamos o cliente OpenAI.
 */
class FireworksService implements AIProviderInterface
{
    /**
     * Capacidades do provedor Fireworks AI
     */
    private const CAPABILITIES = [
        'text' => true,
        'audio' => false, // Fireworks AI não suporta áudio
        'images' => true, // Fireworks AI suporta alguns modelos de imagem
        'documents' => false, // Fireworks AI não suporta documentos nativamente
        'streaming' => true,
        'function_calling' => true,
        'max_tokens' => 32768, // Varia por modelo
        'provider' => 'fireworks'
    ];

    /**
     * Obtém cliente Fireworks AI configurado (usa OpenAI client pois é compatível)
     */
    private function getClient(Agents $agent)
    {
        $user = User::where('uuid', $agent->user_id)->first();

        if (!$user) {
            throw new \Exception('Usuário não encontrado.');
        }

        $userApi = $user->getApiForPlatform('fireworks');

        if (!$userApi || empty($userApi->api_key)) {
            throw new \Exception('Usuário sem chave de API configurada para Fireworks AI.');
        }

        $httpClient = new Client([
            'timeout' => 120,
            'connect_timeout' => 30,
            'verify' => config('services.http.verify_ssl', true)
        ]);

        return OpenAI::factory()
            ->withApiKey($userApi->api_key)
            ->withBaseUri('https://api.fireworks.ai/inference/v1')
            ->withHttpClient($httpClient)
            ->make();
    }

    /**
     * Normaliza o nome do modelo Fireworks AI
     */
    private function normalizeModelName(string $model): string
    {
        $modelMapping = [
            // Llama models
            'llama-3.1-405b' => 'accounts/fireworks/models/llama-v3p1-405b-instruct',
            'llama-3.1-70b' => 'accounts/fireworks/models/llama-v3p1-70b-instruct',
            'llama-3.1-8b' => 'accounts/fireworks/models/llama-v3p1-8b-instruct',
            'llama-3-70b' => 'accounts/fireworks/models/llama-v3-70b-instruct',
            'llama-3-8b' => 'accounts/fireworks/models/llama-v3-8b-instruct',

            // Mixtral models
            'mixtral-8x7b' => 'accounts/fireworks/models/mixtral-8x7b-instruct',
            'mixtral-8x22b' => 'accounts/fireworks/models/mixtral-8x22b-instruct',

            // Qwen models
            'qwen-2.5-72b' => 'accounts/fireworks/models/qwen2p5-72b-instruct',
            'qwen-2.5-7b' => 'accounts/fireworks/models/qwen2p5-7b-instruct',

            // DeepSeek models
            'deepseek-v3' => 'accounts/fireworks/models/deepseek-v3',
            'deepseek-r1' => 'accounts/fireworks/models/deepseek-r1',

            // Gemma models
            'gemma-2-9b' => 'accounts/fireworks/models/gemma2-9b-it',
            'gemma-2-27b' => 'accounts/fireworks/models/gemma2-27b-it',

            // Fallbacks
            'fireworks' => 'accounts/fireworks/models/llama-v3p1-8b-instruct',
            'llama' => 'accounts/fireworks/models/llama-v3p1-8b-instruct',
        ];

        $normalizedModel = strtolower(trim($model));

        if (isset($modelMapping[$normalizedModel])) {
            Log::info("Fireworks AI: Modelo '{$model}' mapeado para '{$modelMapping[$normalizedModel]}'");
            return $modelMapping[$normalizedModel];
        }

        // Se já está no formato completo, usar como está
        if (str_contains($model, 'accounts/fireworks/models/')) {
            return $model;
        }

        Log::warning("Fireworks AI: Modelo '{$model}' não reconhecido, usando 'accounts/fireworks/models/llama-v3p1-8b-instruct' como fallback");
        return 'accounts/fireworks/models/llama-v3p1-8b-instruct';
    }

    /**
     * Gera resposta usando Fireworks AI API.
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

            $model = $this->normalizeModelName($agent->model ?? 'llama-3.1-8b');

            // Configurações padrão para Fireworks AI
            $defaultOptions = [
                'model' => $model,
                'messages' => $messages,
                'max_tokens' => $agent->max_characters ? min($agent->max_characters, 32768) : ($settings->max_characters ?? 500),
                'temperature' => $options['temperature'] ?? 0.7,
                'stream' => false,
            ];

            $params = array_merge($defaultOptions, $options);

            Log::info("Fireworks AI API Request", [
                'agent_id' => $agent->uuid,
                'model' => $params['model'],
                'max_tokens' => $params['max_tokens'],
                'temperature' => $params['temperature']
            ]);

            $response = $client->chat()->create($params);

            $message = trim($response->choices[0]->message->content);

            // Fireworks AI fornece usage details como OpenAI
            $usage = $response->usage ?? null;
            if ($usage) {
                $promptTokens = $usage->promptTokens ?? 0;
                $completionTokens = $usage->completionTokens ?? 0;
                $totalTokens = $usage->totalTokens ?? 0;
            } else {
                // Estimativa baseada no tamanho da resposta
                $promptTokens = $this->estimateTokens($messages);
                $completionTokens = $this->estimateTokens([$message]);
                $totalTokens = $promptTokens + $completionTokens;
            }

            Log::info("Fireworks AI API Response", [
                'agent_id' => $agent->uuid,
                'model' => $params['model'],
                'tokens' => $totalTokens,
                'message_length' => strlen($message)
            ]);

            return [
                'message' => $message,
                'tokens' => $totalTokens,
                'prompt_tokens' => $promptTokens,
                'completion_tokens' => $completionTokens,
                'model' => $params['model']
            ];
        } catch (\Exception $e) {
            Log::error("Erro na Fireworks AI API: {$e->getMessage()}", [
                'agent_id' => $agent->uuid,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'message' => 'Desculpe, não consegui processar sua mensagem. Tente novamente.',
                'tokens' => 0,
                'model' => $agent->model ?? 'accounts/fireworks/models/llama-v3p1-8b-instruct',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Estimativa simples de tokens
     */
    private function estimateTokens($content): int
    {
        if (is_array($content)) {
            $text = '';
            foreach ($content as $item) {
                if (is_array($item) && isset($item['content'])) {
                    $text .= $item['content'] . ' ';
                } elseif (is_string($item)) {
                    $text .= $item . ' ';
                }
            }
        } else {
            $text = (string) $content;
        }

        // Estimativa aproximada: 1 token ≈ 4 caracteres
        return (int) ceil(strlen($text) / 4);
    }

    /**
     * Fireworks AI não suporta transcrição de áudio
     */
    public function transcribeAudio(string $audioPathOrUrl, Agents $agent): ?string
    {
        Log::info('Fireworks AI não suporta transcrição de áudio', [
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
     * Retorna lista de modelos disponíveis do Fireworks AI
     */
    public function getModels(): array
    {
        return [
            'llama-3.1-405b' => 'Llama 3.1 405B Instruct',
            'llama-3.1-70b' => 'Llama 3.1 70B Instruct',
            'llama-3.1-8b' => 'Llama 3.1 8B Instruct',
            'llama-3-70b' => 'Llama 3 70B Instruct',
            'llama-3-8b' => 'Llama 3 8B Instruct',
            'mixtral-8x7b' => 'Mixtral 8x7B Instruct',
            'mixtral-8x22b' => 'Mixtral 8x22B Instruct',
            'qwen-2.5-72b' => 'Qwen 2.5 72B Instruct',
            'qwen-2.5-7b' => 'Qwen 2.5 7B Instruct',
            'gemma-2-9b' => 'Gemma 2 9B IT',
            'gemma-2-27b' => 'Gemma 2 27B IT',
            'deepseek-v3' => 'DeepSeek V3',
        ];
    }

    /**
     * Valida se um modelo é suportado pelo Fireworks AI
     */
    public function validateModel(string $model): bool
    {
        $supportedModels = array_keys($this->getModels());

        // Verifica correspondência exata
        if (in_array($model, $supportedModels)) {
            return true;
        }

        // Verifica correspondências parciais para modelos Fireworks AI
        $patterns = ['llama', 'mixtral', 'qwen', 'gemma', 'deepseek', 'fireworks'];
        foreach ($patterns as $pattern) {
            if (str_contains(strtolower($model), $pattern)) {
                return true;
            }
        }

        // Verifica se está no formato completo do Fireworks AI
        if (str_contains($model, 'accounts/fireworks/models/')) {
            return true;
        }

        return false;
    }
}
