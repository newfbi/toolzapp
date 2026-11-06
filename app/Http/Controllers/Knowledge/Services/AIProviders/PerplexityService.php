<?php

namespace App\Http\Controllers\Knowledge\Services\AIProviders;

use App\Models\Agents;
use App\Models\Settings;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use GuzzleHttp\Client;
use OpenAI;

/**
 * Serviço para integração com Perplexity AI API.
 * Perplexity é compatível com OpenAI API, então usamos o cliente OpenAI.
 */
class PerplexityService implements AIProviderInterface
{
    /**
     * Capacidades do provedor Perplexity
     */
    private const CAPABILITIES = [
        'text' => true,
        'audio' => false, // Perplexity não suporta áudio
        'images' => false, // Perplexity não suporta geração de imagens
        'documents' => true, // Perplexity suporta análise de documentos
        'streaming' => true,
        'function_calling' => false,
        'web_search' => true, // Característica única do Perplexity
        'max_tokens' => 8000,
        'provider' => 'perplexity'
    ];

    /**
     * Obtém cliente Perplexity configurado (usa OpenAI client pois é compatível)
     */
    private function getClient(Agents $agent)
    {
        $user = User::where('uuid', $agent->user_id)->first();

        if (!$user) {
            throw new \Exception('Usuário não encontrado.');
        }

        $userApi = $user->getApiForPlatform('perplexity');

        if (!$userApi || empty($userApi->api_key)) {
            throw new \Exception('Usuário sem chave de API configurada para Perplexity.');
        }

        $httpClient = new Client([
            'timeout' => 120,
            'connect_timeout' => 30,
            'verify' => config('services.http.verify_ssl', true)
        ]);

        return OpenAI::factory()
            ->withApiKey($userApi->api_key)
            ->withBaseUri('https://api.perplexity.ai')
            ->withHttpClient($httpClient)
            ->make();
    }

    /**
     * Normaliza o nome do modelo Perplexity
     */
    private function normalizeModelName(string $model): string
    {
        $modelMapping = [
            'sonar' => 'sonar',
            'sonar-pro' => 'sonar-pro',
            'sonar-reasoning' => 'sonar-reasoning',
            'sonar-reasoning-pro' => 'sonar-reasoning-pro',
            'sonar-deep-research' => 'sonar-deep-research',
            'perplexity' => 'sonar',
            'perplexity-pro' => 'sonar-pro',
        ];

        $normalizedModel = strtolower(trim($model));

        if (isset($modelMapping[$normalizedModel])) {
            Log::info("Perplexity: Modelo '{$model}' mapeado para '{$modelMapping[$normalizedModel]}'");
            return $modelMapping[$normalizedModel];
        }

        $validModels = ['sonar', 'sonar-pro', 'sonar-reasoning', 'sonar-reasoning-pro', 'sonar-deep-research'];

        if (in_array($normalizedModel, $validModels)) {
            return $normalizedModel;
        }

        Log::warning("Perplexity: Modelo '{$model}' não reconhecido, usando 'sonar' como fallback");
        return 'sonar';
    }

    /**
     * Gera resposta usando Perplexity API.
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

            $model = $this->normalizeModelName($agent->model ?? 'sonar');

            // Configurações padrão para Perplexity
            $defaultOptions = [
                'model' => $model,
                'messages' => $messages,
                'max_tokens' => $agent->max_characters ? min($agent->max_characters, 8000) : ($settings->max_characters ?? 500),
                'temperature' => $options['temperature'] ?? 0.7,
                'stream' => false,
            ];

            // Adicionar configurações específicas do Perplexity se necessário
            if (in_array($model, ['sonar-pro', 'sonar-reasoning-pro'])) {
                // Para modelos Pro, pode configurar search_domain_filter ou search_recency_filter
                if (isset($options['search_domain_filter'])) {
                    $defaultOptions['search_domain_filter'] = $options['search_domain_filter'];
                }
                if (isset($options['search_recency_filter'])) {
                    $defaultOptions['search_recency_filter'] = $options['search_recency_filter'];
                }
            }

            $params = array_merge($defaultOptions, $options);

            Log::info("Perplexity API Request", [
                'agent_id' => $agent->uuid,
                'model' => $params['model'],
                'max_tokens' => $params['max_tokens'],
                'temperature' => $params['temperature']
            ]);

            $response = $client->chat()->create($params);

            $message = trim($response->choices[0]->message->content);

            // Perplexity fornece usage details como OpenAI
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

            // Perplexity pode incluir citações e fontes
            $citations = $response->citations ?? [];

            Log::info("Perplexity API Response", [
                'agent_id' => $agent->uuid,
                'model' => $params['model'],
                'tokens' => $totalTokens,
                'message_length' => strlen($message),
                'citations_count' => count($citations)
            ]);

            $result = [
                'message' => $message,
                'tokens' => $totalTokens,
                'prompt_tokens' => $promptTokens,
                'completion_tokens' => $completionTokens,
                'model' => $params['model']
            ];

            // Adicionar citações se disponíveis
            if (!empty($citations)) {
                $result['citations'] = $citations;
            }

            return $result;
        } catch (\Exception $e) {
            Log::error("Erro na Perplexity API: {$e->getMessage()}", [
                'agent_id' => $agent->uuid,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'message' => 'Desculpe, não consegui processar sua mensagem. Tente novamente.',
                'tokens' => 0,
                'model' => $agent->model ?? 'sonar',
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
     * Perplexity não suporta transcrição de áudio
     */
    public function transcribeAudio(string $audioPathOrUrl, Agents $agent): ?string
    {
        Log::info('Perplexity não suporta transcrição de áudio', [
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
     * Retorna lista de modelos disponíveis do Perplexity
     */
    public function getModels(): array
    {
        return [
            'sonar' => 'Sonar (busca web básica)',
            'sonar-pro' => 'Sonar Pro (busca web avançada)',
            'sonar-reasoning' => 'Sonar Reasoning (raciocínio com busca)',
            'sonar-reasoning-pro' => 'Sonar Reasoning Pro (raciocínio avançado)',
            'sonar-deep-research' => 'Sonar Deep Research (pesquisa profunda)',
        ];
    }

    /**
     * Valida se um modelo é suportado pelo Perplexity
     */
    public function validateModel(string $model): bool
    {
        $supportedModels = array_keys($this->getModels());

        // Verifica correspondência exata
        if (in_array($model, $supportedModels)) {
            return true;
        }

        // Verifica correspondências parciais para modelos Perplexity
        $patterns = ['sonar', 'perplexity'];
        foreach ($patterns as $pattern) {
            if (str_contains(strtolower($model), $pattern)) {
                return true;
            }
        }

        return false;
    }
}
