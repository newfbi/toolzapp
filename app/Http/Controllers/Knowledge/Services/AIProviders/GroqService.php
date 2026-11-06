<?php

namespace App\Http\Controllers\Knowledge\Services\AIProviders;

use App\Models\Agents;
use App\Models\Settings;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use GuzzleHttp\Client;
use OpenAI;

/**
 * Serviço para integração com Groq API.
 * Groq é compatível com OpenAI API, então usamos o cliente OpenAI.
 */
class GroqService implements AIProviderInterface
{
    /**
     * Capacidades do provedor Groq
     */
    private const CAPABILITIES = [
        'text' => true,
        'audio' => false, // Groq não suporta áudio
        'images' => false, // Groq não suporta imagens
        'documents' => false, // Groq não suporta documentos
        'streaming' => true,
        'function_calling' => false,
        'max_tokens' => 8192,
        'provider' => 'groq'
    ];
    /**
     * Obtém cliente Groq configurado (usa OpenAI client pois é compatível)
     */
    private function getClient(Agents $agent)
    {
        $user = User::where('uuid', $agent->user_id)->first();

        if (!$user) {
            throw new \Exception('Usuário não encontrado.');
        }

        $userApi = $user->getApiForPlatform('groq');

        if (!$userApi || empty($userApi->api_key)) {
            throw new \Exception('Usuário sem chave de API configurada para Groq.');
        }

        $httpClient = new Client([
            'timeout' => 120,
            'connect_timeout' => 30,
            'verify' => config('services.http.verify_ssl', true)
        ]);

        return OpenAI::factory()
            ->withApiKey($userApi->api_key)
            ->withBaseUri('https://api.groq.com/openai/v1')
            ->withHttpClient($httpClient)
            ->make();
    }

    /**
     * Normaliza nomes de modelos Groq
     */
     private function normalizeModelName(?string $model): string
     {
         if (empty($model)) {
             return 'llama-3.3-70b-versatile';
         }

         $modelMap = [
             'llama2-70b-4096' => 'llama-3.3-70b-versatile',
             'llama-2-70b' => 'llama-3.3-70b-versatile',
             'llama-3-70b-8192' => 'llama-3.3-70b-versatile',
             'llama-3-8b-8192' => 'llama-3.1-8b-instant',
             'llama-3.1-70b-versatile' => 'llama-3.3-70b-versatile',
             'llama-3.3-70b-versatile' => 'llama-3.3-70b-versatile',
             'llama-3.1-8b-instant' => 'llama-3.1-8b-instant',
             'mixtral-8x7b-32768' => 'mixtral-8x7b-32768',
             'deepseek-r1-distill-llama-70b' => 'deepseek-r1-distill-llama-70b',
         ];

         $lower = strtolower(trim($model));
         return $modelMap[$lower] ?? $model;
     }

    /**
     * Gera resposta usando Groq API.
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
            $targetModel = $this->normalizeModelName($agent->model ?? 'llama-3.3-70b-versatile');

            // Configurações padrão para Groq
            $defaultOptions = [
                'model' => $targetModel,
                'messages' => $messages,
                'max_tokens' => $agent->max_characters ? min($agent->max_characters, 4096) : ($settings->max_characters ?? 600),
                'temperature' => $options['temperature'] ?? 0.7,
            ];

            $params = array_merge($defaultOptions, $options);

            $response = $client->chat()->create($params);

            $message = trim($response->choices[0]->message->content);

            // Groq pode não fornecer usage details como OpenAI
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

            return [
                'message' => $message,
                'tokens' => $totalTokens,
                'prompt_tokens' => $promptTokens,
                'completion_tokens' => $completionTokens,
                'model' => $params['model']
            ];
        } catch (\Exception $e) {
            Log::error("Erro na Groq API: {$e->getMessage()}", [
                'agent_id' => $agent->uuid,
                'error' => $e->getMessage()
            ]);

            return [
                'message' => 'Desculpe, não consegui processar sua mensagem. Tente novamente.',
                'tokens' => 0,
                'model' => $agent->model ?? 'llama2-70b-4096',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Estimativa simples de tokens (não precisa ser precisa para Groq)
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
     * Groq não suporta transcrição de áudio
     */
    public function transcribeAudio(string $audioPathOrUrl, Agents $agent): ?string
    {
        Log::info('Groq não suporta transcrição de áudio', [
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
     * Retorna lista de modelos disponíveis do Groq
     */
    public function getModels(): array
    {
        return [
            'llama2-70b-4096' => 'Llama 2 70B',
            'llama3-8b-8192' => 'Llama 3 8B',
            'llama3-70b-8192' => 'Llama 3 70B',
            'mixtral-8x7b-32768' => 'Mixtral 8x7B',
        ];
    }

    /**
     * Valida se um modelo é suportado pelo Groq
     */
    public function validateModel(string $model): bool
    {
        $supportedModels = array_keys($this->getModels());
        $modelLower = strtolower($model);

        // Verifica correspondência exata
        if (in_array($modelLower, array_map('strtolower', $supportedModels))) {
            return true;
        }

        // Verifica padrões Groq
        $patterns = ['llama', 'mixtral', 'groq'];
        foreach ($patterns as $pattern) {
            if (str_contains($modelLower, $pattern)) {
                return true;
            }
        }

        return false;
    }
}
