<?php

namespace App\Http\Controllers\Knowledge\Services\AIProviders;

use App\Models\Agents;
use App\Models\Settings;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use GuzzleHttp\Client;
use OpenAI;

/**
 * Serviço para integração com Together AI API.
 * Together AI é compatível com OpenAI API, então usamos o cliente OpenAI.
 */
class TogetherService implements AIProviderInterface
{
    /**
     * Capacidades do provedor Together AI
     */
    private const CAPABILITIES = [
        'text' => true,
        'audio' => false, // Together AI não suporta áudio
        'images' => true, // Together AI suporta alguns modelos de imagem
        'documents' => false, // Together AI não suporta documentos nativamente
        'streaming' => true,
        'function_calling' => true,
        'max_tokens' => 32768, // Varia por modelo
        'provider' => 'together'
    ];

    /**
     * Obtém cliente Together AI configurado (usa OpenAI client pois é compatível)
     */
    private function getClient(Agents $agent)
    {
        $user = User::where('uuid', $agent->user_id)->first();

        if (!$user) {
            throw new \Exception('Usuário não encontrado.');
        }

        $userApi = $user->getApiForPlatform('together');

        if (!$userApi || empty($userApi->api_key)) {
            throw new \Exception('Usuário sem chave de API configurada para Together AI.');
        }

        $httpClient = new Client([
            'timeout' => 120,
            'connect_timeout' => 30,
            'verify' => config('services.http.verify_ssl', true)
        ]);

        return OpenAI::factory()
            ->withApiKey($userApi->api_key)
            ->withBaseUri('https://api.together.xyz/v1')
            ->withHttpClient($httpClient)
            ->make();
    }

    /**
     * Normaliza o nome do modelo Together AI
     */
    private function normalizeModelName(string $model): string
    {
        $modelMapping = [
            // Meta Llama models
            'llama-3.1-405b' => 'meta-llama/Meta-Llama-3.1-405B-Instruct-Turbo',
            'llama-3.1-70b' => 'meta-llama/Meta-Llama-3.1-70B-Instruct-Turbo',
            'llama-3.1-8b' => 'meta-llama/Meta-Llama-3.1-8B-Instruct-Turbo',
            'llama-3-70b' => 'meta-llama/Meta-Llama-3-70B-Instruct-Turbo',
            'llama-3-8b' => 'meta-llama/Meta-Llama-3-8B-Instruct-Turbo',

            // Mistral models
            'mixtral-8x7b' => 'mistralai/Mixtral-8x7B-Instruct-v0.1',
            'mixtral-8x22b' => 'mistralai/Mixtral-8x22B-Instruct-v0.1',
            'mistral-7b' => 'mistralai/Mistral-7B-Instruct-v0.1',

            // Qwen models
            'qwen-2.5-72b' => 'Qwen/Qwen2.5-72B-Instruct-Turbo',
            'qwen-2.5-7b' => 'Qwen/Qwen2.5-7B-Instruct-Turbo',

            // DeepSeek models
            'deepseek-r1' => 'deepseek-ai/DeepSeek-R1',
            'deepseek-v3' => 'deepseek-ai/DeepSeek-V3',

            // Fallbacks
            'together' => 'meta-llama/Meta-Llama-3.1-8B-Instruct-Turbo',
            'llama' => 'meta-llama/Meta-Llama-3.1-8B-Instruct-Turbo',
        ];

        $normalizedModel = strtolower(trim($model));

        if (isset($modelMapping[$normalizedModel])) {
            Log::info("Together AI: Modelo '{$model}' mapeado para '{$modelMapping[$normalizedModel]}'");
            return $modelMapping[$normalizedModel];
        }

        // Se já está no formato completo, usar como está
        if (str_contains($model, '/')) {
            return $model;
        }

        Log::warning("Together AI: Modelo '{$model}' não reconhecido, usando 'meta-llama/Meta-Llama-3.1-8B-Instruct-Turbo' como fallback");
        return 'meta-llama/Meta-Llama-3.1-8B-Instruct-Turbo';
    }

    /**
     * Gera resposta usando Together AI API.
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

            // Configurações padrão para Together AI
            $defaultOptions = [
                'model' => $model,
                'messages' => $messages,
                'max_tokens' => $agent->max_characters ? min($agent->max_characters, 32768) : ($settings->max_characters ?? 500),
                'temperature' => $options['temperature'] ?? 0.7,
                'stream' => false,
            ];

            $params = array_merge($defaultOptions, $options);

            Log::info("Together AI API Request", [
                'agent_id' => $agent->uuid,
                'model' => $params['model'],
                'max_tokens' => $params['max_tokens'],
                'temperature' => $params['temperature']
            ]);

            $response = $client->chat()->create($params);

            $message = trim($response->choices[0]->message->content);

            // Together AI fornece usage details como OpenAI
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

            Log::info("Together AI API Response", [
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
            Log::error("Erro na Together AI API: {$e->getMessage()}", [
                'agent_id' => $agent->uuid,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'message' => 'Desculpe, não consegui processar sua mensagem. Tente novamente.',
                'tokens' => 0,
                'model' => $agent->model ?? 'meta-llama/Meta-Llama-3.1-8B-Instruct-Turbo',
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
     * Together AI não suporta transcrição de áudio
     */
    public function transcribeAudio(string $audioPathOrUrl, Agents $agent): ?string
    {
        Log::info('Together AI não suporta transcrição de áudio', [
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
     * Retorna lista de modelos disponíveis do Together AI
     */
    public function getModels(): array
    {
        return [
            'llama-3.1-405b' => 'Llama 3.1 405B Instruct Turbo',
            'llama-3.1-70b' => 'Llama 3.1 70B Instruct Turbo',
            'llama-3.1-8b' => 'Llama 3.1 8B Instruct Turbo',
            'llama-3-70b' => 'Llama 3 70B Instruct Turbo',
            'llama-3-8b' => 'Llama 3 8B Instruct Turbo',
            'mixtral-8x7b' => 'Mixtral 8x7B Instruct',
            'mixtral-8x22b' => 'Mixtral 8x22B Instruct',
            'qwen-2.5-72b' => 'Qwen 2.5 72B Instruct Turbo',
            'qwen-2.5-7b' => 'Qwen 2.5 7B Instruct Turbo',
            'deepseek-r1' => 'DeepSeek R1',
            'deepseek-v3' => 'DeepSeek V3',
        ];
    }

    /**
     * Valida se um modelo é suportado pelo Together AI
     */
    public function validateModel(string $model): bool
    {
        $supportedModels = array_keys($this->getModels());

        // Verifica correspondência exata
        if (in_array($model, $supportedModels)) {
            return true;
        }

        // Verifica correspondências parciais para modelos Together AI
        $patterns = ['llama', 'mixtral', 'qwen', 'deepseek', 'together'];
        foreach ($patterns as $pattern) {
            if (str_contains(strtolower($model), $pattern)) {
                return true;
            }
        }

        // Verifica se está no formato completo do Together AI
        if (str_contains($model, '/')) {
            return true;
        }

        return false;
    }
}
