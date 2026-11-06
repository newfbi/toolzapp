<?php

namespace App\Http\Controllers\Knowledge\Services\AIProviders;

use App\Models\Agents;
use App\Models\Settings;
use App\Models\User;
use App\Support\AiModelCatalog;
use Illuminate\Support\Facades\Log;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Storage;
use OpenAI;

/**
 * Serviço para integração com OpenAI Chat Completions API.
 * Substitui o antigo sistema de Assistants (deprecated) por Chat Completions moderno.
 */
class OpenAiChatService implements AIProviderInterface
{
    /**
     * Capacidades do provedor OpenAI
     */
    private const CAPABILITIES = [
        'text' => true,
        'audio' => true, // OpenAI suporta Whisper para transcrição
        'images' => true, // OpenAI suporta DALL-E e análise de imagens
        'documents' => false, // OpenAI não suporta documentos diretamente
        'streaming' => true,
        'function_calling' => true,
        'max_completion_tokens' => 16385,
        'provider' => 'openai'
    ];

    private $useSystemApi = false;
    private ?string $modelOverride = null;

    /**
     * Construtor
     *
     * @param bool $useSystemApi Define se deve usar API do sistema
     * @param string|null $modelOverride Modelo selecionado pelo chat, quando houver
     */
    public function __construct(bool $useSystemApi = false, ?string $modelOverride = null)
    {
        $this->useSystemApi = $useSystemApi;
        $this->modelOverride = $modelOverride !== null
            ? AiModelCatalog::normalize($modelOverride)
            : null;
    }

    /**
     * Obtém cliente OpenAI configurado
     */
    private function getClient(Agents $agent)
    {
        $apiKey = null;

        if ($this->useSystemApi) {
            // Usar API key do sistema
            $settings = Settings::first();

            if (!$settings || empty($settings->api_key)) {
                throw new \Exception('API key do sistema não configurada para OpenAI.');
            }

            $apiKey = $settings->api_key;

            Log::info('Usando API key do sistema para OpenAI', [
                'agent_id' => $agent->uuid
            ]);
        } else {
            // Usar API key do usuário
            $user = User::where('uuid', $agent->user_id)->first();

            if (!$user) {
                throw new \Exception('Usuário não encontrado.');
            }

            $userApi = $user->getApiForPlatform('openai');

            if (!$userApi || empty($userApi->api_key)) {
                throw new \Exception('Usuário sem chave de API configurada para OpenAI.');
            }

            $apiKey = $userApi->api_key;
        }

        $httpClient = new Client([
            'timeout' => 120,
            'connect_timeout' => 30,
            'verify' => config('services.http.verify_ssl', true)
        ]);

        return OpenAI::factory()
            ->withApiKey($apiKey)
            ->withHttpClient($httpClient)
            ->make();
    }

    /**
     * Garante que toda chamada de conversação use um modelo permitido.
     */
    private function normalizeModelName(?string $model): string
    {
        return AiModelCatalog::normalize($model);
    }

    /**
     * Gera resposta usando Chat Completions API com memória contextual.
     *
     * @param array $messages Array de mensagens no formato ['role' => '...', 'content' => '...']
     * @param Agents $agent Agente configurado
     * @param array $options Opções adicionais (max_completion_tokens, reasoning_effort, etc)
     * @return array ['message' => string, 'tokens' => int]
     */
    public function getCompletion(array $messages, Agents $agent, array $options = []): array
    {
        try {
            $client = $this->getClient($agent);
            $settings = Settings::first();
            $targetModel = $this->normalizeModelName($this->modelOverride ?? $agent->model);
            $isReasoningModel = str_starts_with($targetModel, 'gpt-6') ||
                str_starts_with($targetModel, 'o1') ||
                str_starts_with($targetModel, 'o3');

            $defaultMaxCompletionTokens = $agent->max_characters
                ? min($agent->max_characters, 4000)
                : ($settings->max_characters ?? 600);

            // A API atual usa exclusivamente max_completion_tokens.
            $maxCompletionTokens = $options['max_completion_tokens']
                ?? $defaultMaxCompletionTokens;

            // Configurações padrão
            $defaultOptions = [
                'model' => $targetModel,
                'messages' => $messages,
                'max_completion_tokens' => $maxCompletionTokens,
            ];

            if ($isReasoningModel) {
                // GPT-6/o-series com raciocínio ativo não aceitam parâmetros
                // de amostragem como temperature/top_p.
                unset($options['temperature'], $options['top_p'], $options['top_logprobs'], $options['logprobs']);
            } else {
                $defaultOptions['temperature'] = $options['temperature'] ?? 0.7;
            }

            // Mescla com opções customizadas
            $params = array_merge($defaultOptions, $options);

            $response = $client->chat()->create($params);

            $message = trim($response->choices[0]->message->content ?? '');

            $usage = $response->usage;
            $promptTokens = $usage->promptTokens ?? 0;
            $completionTokens = $usage->completionTokens ?? 0;
            $totalTokens = $usage->totalTokens ?? 0;

            return [
                'message' => $message,
                'tokens' => $totalTokens,
                'prompt_tokens' => $promptTokens,
                'completion_tokens' => $completionTokens,
                'model' => $params['model']
            ];
        } catch (\Exception $e) {
            Log::error("Erro na Chat Completions API OpenAI: {$e->getMessage()}", [
                'agent_id' => $agent->uuid,
                'error' => $e->getMessage()
            ]);

            return [
                'message' => 'Desculpe, no momento não estamos atendendo. Tente novamente em outro horário. Agradecemos a compreensão.',
                'tokens' => 0,
                'model' => $this->normalizeModelName($agent->model),
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Transcreve áudio usando Whisper API.
     * Aceita URL ou caminho de arquivo local.
     */
    public function transcribeAudio(string $audioPathOrUrl, Agents $agent): ?string
    {
        try {
            $client = $this->getClient($agent);

            $fullPath = $audioPathOrUrl;
            $isTemporary = false;

            // Se for URL, baixa o arquivo
            if (filter_var($audioPathOrUrl, FILTER_VALIDATE_URL)) {
                $audioContent = file_get_contents($audioPathOrUrl);
                if (!$audioContent) {
                    Log::error('Não foi possível baixar o áudio da URL: ' . $audioPathOrUrl);
                    return null;
                }

                // Cria diretório se não existir
                if (!Storage::disk('local')->exists('audios')) {
                    Storage::disk('local')->makeDirectory('audios');
                }

                // Cria arquivo temporário
                $filename = 'audios/' . uniqid('audio_', true) . '.ogg';
                Storage::disk('local')->put($filename, $audioContent);
                $fullPath = storage_path('app/' . $filename);
                $isTemporary = true;
            } else {
                // 🆕 Não é URL - tentar resolver como caminho relativo ou absoluto
                if (file_exists($audioPathOrUrl)) {
                    // Caminho absoluto válido
                    $fullPath = $audioPathOrUrl;
                } else {
                    // Tentar como caminho relativo ao storage/app/
                    $relativePath = $audioPathOrUrl;
                    // Se já começa com storage/app/, remover para evitar duplicação
                    if (str_starts_with($relativePath, 'storage/app/')) {
                        $relativePath = str_replace('storage/app/', '', $relativePath);
                    }
                    $fullPath = storage_path('app/' . $relativePath);
                }
            }

            // Verifica se arquivo existe
            if (!file_exists($fullPath)) {
                Log::error('Arquivo de áudio não encontrado', [
                    'original_path' => $audioPathOrUrl,
                    'resolved_path' => $fullPath
                ]);
                return null;
            }

            // Transcreve com Whisper
            $result = $client->audio()->transcribe([
                'file' => fopen($fullPath, 'r'),
                'model' => 'whisper-1',
                'response_format' => 'text',
                'language' => 'pt'
            ]);

            // Remove arquivo temporário se foi criado
            if ($isTemporary && file_exists($fullPath)) {
                unlink($fullPath);
            }

            return trim((string) $result->text);
        } catch (\Exception $e) {
            Log::error("Erro ao transcrever áudio: " . $e->getMessage());
            return null;
        }
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
     * Retorna lista de modelos disponíveis do OpenAI
     */
    public function getModels(): array
    {
        return AiModelCatalog::all();
    }

    /**
     * Valida se um modelo é suportado pelo OpenAI
     */
    public function validateModel(string $model): bool
    {
        return AiModelCatalog::isAllowed($model);
    }
}
