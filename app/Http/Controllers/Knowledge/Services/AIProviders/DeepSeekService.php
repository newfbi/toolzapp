<?php

namespace App\Http\Controllers\Knowledge\Services\AIProviders;

use App\Models\Agents;
use App\Models\Settings;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use GuzzleHttp\Client;
use OpenAI;

/**
 * Serviço para integração com DeepSeek API.
 * DeepSeek é compatível com OpenAI API, então usamos o cliente OpenAI.
 */
class DeepSeekService implements AIProviderInterface
{
    /**
     * Capacidades do provedor DeepSeek
     */
    private const CAPABILITIES = [
        'text' => true,
        'audio' => false, // DeepSeek não suporta áudio
        'images' => false, // DeepSeek não suporta imagens
        'documents' => false, // DeepSeek não suporta documentos
        'streaming' => true,
        'function_calling' => false,
        'max_tokens' => 8192,
        'provider' => 'deepseek'
    ];

    /**
     * Obtém cliente DeepSeek configurado (usa OpenAI client pois é compatível)
     */
    private function getClient(Agents $agent)
    {
        $user = User::where('uuid', $agent->user_id)->first();

        if (!$user) {
            throw new \Exception('Usuário não encontrado.');
        }

        $userApi = $user->getApiForPlatform('deepseek');

        if (!$userApi || empty($userApi->api_key)) {
            throw new \Exception('Usuário sem chave de API configurada para DeepSeek.');
        }

        $httpClient = new Client([
            'timeout' => 120,
            'connect_timeout' => 30,
            'verify' => config('services.http.verify_ssl', true)
        ]);

        return OpenAI::factory()
            ->withApiKey($userApi->api_key)
            ->withBaseUri('https://api.deepseek.com/v1')
            ->withHttpClient($httpClient)
            ->make();
    }

    /**
     * Normaliza o nome do modelo DeepSeek
     */
    private function normalizeModelName(string $model): string
    {
        $modelMapping = [
            'deepseek-v3.2' => 'deepseek-chat',
            'deepseek-v3.1' => 'deepseek-chat',
            'deepseek-v2.5' => 'deepseek-chat',
            'deepseek-chat' => 'deepseek-chat',
            'deepseek-reasoner' => 'deepseek-reasoner',
            'deepseek-r1' => 'deepseek-reasoner',
            'deepseek' => 'deepseek-chat',
        ];

        $normalizedModel = strtolower(trim($model));

        if (isset($modelMapping[$normalizedModel])) {
            Log::info("DeepSeek: Modelo '{$model}' mapeado para '{$modelMapping[$normalizedModel]}'");
            return $modelMapping[$normalizedModel];
        }

        $validModels = ['deepseek-chat', 'deepseek-reasoner'];

        if (in_array($normalizedModel, $validModels)) {
            return $normalizedModel;
        }

        Log::warning("DeepSeek: Modelo '{$model}' não reconhecido, usando 'deepseek-chat' como fallback");
        return 'deepseek-chat';
    }

    /**
     * Gera resposta usando DeepSeek API.
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

            $model = $this->normalizeModelName($agent->model ?? 'deepseek-chat');

            // Configurações padrão para DeepSeek
            $defaultOptions = [
                'model' => $model,
                'messages' => $messages,
                'max_tokens' => $agent->max_characters ? min($agent->max_characters, 8192) : ($settings->max_characters ?? 500),
                'temperature' => $options['temperature'] ?? 0.7,
                'stream' => false,
            ];

            $params = array_merge($defaultOptions, $options);

            Log::info("DeepSeek API Request", [
                'agent_id' => $agent->uuid,
                'model' => $params['model'],
                'max_tokens' => $params['max_tokens'],
                'temperature' => $params['temperature']
            ]);

            $response = $client->chat()->create($params);

            $message = trim($response->choices[0]->message->content);

            // DeepSeek fornece usage details como OpenAI
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

            Log::info("DeepSeek API Response", [
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
            Log::error("Erro na DeepSeek API: {$e->getMessage()}", [
                'agent_id' => $agent->uuid,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'message' => 'Desculpe, não consegui processar sua mensagem. Tente novamente.',
                'tokens' => 0,
                'model' => $agent->model ?? 'deepseek-chat',
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
     * DeepSeek não suporta transcrição de áudio
     */
    public function transcribeAudio(string $audioPathOrUrl, Agents $agent): ?string
    {
        Log::info('DeepSeek não suporta transcrição de áudio', [
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
     * Retorna lista de modelos disponíveis do DeepSeek
     */
    public function getModels(): array
    {
        return [
            'deepseek-chat' => 'DeepSeek Chat (V3.2)',
            'deepseek-reasoner' => 'DeepSeek Reasoner (R1)',
            'deepseek-v3.2' => 'DeepSeek V3.2',
            'deepseek-v3.1' => 'DeepSeek V3.1',
            'deepseek-v2.5' => 'DeepSeek V2.5',
        ];
    }

    /**
     * Valida se um modelo é suportado pelo DeepSeek
     */
    public function validateModel(string $model): bool
    {
        $supportedModels = array_keys($this->getModels());

        // Verifica correspondência exata
        if (in_array($model, $supportedModels)) {
            return true;
        }

        // Verifica correspondências parciais para modelos DeepSeek
        $patterns = ['deepseek'];
        foreach ($patterns as $pattern) {
            if (str_contains(strtolower($model), $pattern)) {
                return true;
            }
        }

        return false;
    }
}
