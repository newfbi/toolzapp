<?php

namespace App\Http\Controllers\Knowledge\Services;

use App\Http\Controllers\Knowledge\Services\AIProviders\AnthropicClaudeService;
use App\Http\Controllers\Knowledge\Services\AIProviders\CohereService;
use App\Http\Controllers\Knowledge\Services\AIProviders\DeepSeekService;
use App\Http\Controllers\Knowledge\Services\AIProviders\FireworksService;
use App\Http\Controllers\Knowledge\Services\AIProviders\GoogleGeminiService;
use App\Http\Controllers\Knowledge\Services\AIProviders\GroqService;
use App\Http\Controllers\Knowledge\Services\AIProviders\MetaService;
use App\Http\Controllers\Knowledge\Services\AIProviders\MistralService;
use App\Http\Controllers\Knowledge\Services\AIProviders\OpenAiChatService;
use App\Http\Controllers\Knowledge\Services\AIProviders\PerplexityService;
use App\Http\Controllers\Knowledge\Services\AIProviders\TogetherService;
use App\Http\Controllers\Knowledge\Services\AIProviders\XAIService;
use App\Http\Controllers\Knowledge\Services\AIProviders\AIProviderInterface;
use App\Models\Agents;
use App\Models\Chats;
use App\Models\Settings;
use App\Support\AiModelCatalog;
use Illuminate\Support\Facades\Log;

/**
 * Factory para criar instâncias dos provedores de IA baseado na plataforma
 */
class AIProviderFactory
{
    /**
     * Mapeamento de slugs de plataforma para classes de serviço
     */
    private static $providerMap = [
        'openai' => OpenAiChatService::class,
        'claude' => AnthropicClaudeService::class,
        'gemini' => GoogleGeminiService::class,
        'groq' => GroqService::class,
        'xai' => XAIService::class,
        'meta' => MetaService::class,
        'mistral' => MistralService::class,
        'cohere' => CohereService::class,
        'deepseek' => DeepSeekService::class,
        'perplexity' => PerplexityService::class,
        'together' => TogetherService::class,
        'fireworks' => FireworksService::class,
    ];

    /**
     * Verifica se o sistema deve usar a API key do sistema ao invés da API key do usuário
     *
     * @return bool
     */
    public static function shouldUseSystemApi(): bool
    {
        try {
            $settings = Settings::first();

            // Verifica se a configuração de usar API do sistema está ativa
            return $settings && !empty($settings->use_system_api) &&
                filter_var($settings->use_system_api, FILTER_VALIDATE_BOOLEAN) &&
                !empty($settings->api_key);
        } catch (\Exception $e) {
            Log::warning('Erro ao verificar configuração de API do sistema', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * 🆕 Verifica se um provedor está usando API do sistema
     * Útil para determinar se deve verificar/debitar créditos do usuário
     *
     * @param AIProviderInterface $provider
     * @return bool
     */
    public static function isUsingSystemApi(AIProviderInterface $provider): bool
    {
        // Se for OpenAiChatService, verificar se está usando API do sistema
        if ($provider instanceof OpenAiChatService) {
            // Usa reflexão para acessar propriedade privada useSystemApi
            $reflection = new \ReflectionClass($provider);
            $property = $reflection->getProperty('useSystemApi');
            $property->setAccessible(true);
            return $property->getValue($provider) === true;
        }

        return false;
    }

    /**
     * 🆕 Verifica se o usuário tem APIs ativas configuradas
     *
     * @param Agents $agent
     * @return bool
     */
    public static function userHasActiveApis(Agents $agent): bool
    {
        if (!$agent->user) {
            return false;
        }

        $activeApisCount = \App\Models\UserApi::where('user_id', $agent->user->uuid)
            ->where('is_active', true)
            ->count();

        return $activeApisCount > 0;
    }

    /**
     * Cria uma instância do provedor de IA baseado no agente e chat
     *
     * Hierarquia de prioridade: Chat > Agent > UserApi > Default
     *
     * @param Agents $agent
     * @param Chats|null $chat Opcional - para usar selected_model do chat
     * @return AIProviderInterface
     * @throws \Exception
     */
    public static function create(Agents $agent, Chats $chat = null): AIProviderInterface
    {
        // O modelo de conversação é sempre normalizado pelo catálogo oficial.
        // Isso impede que um valor legado seja encaminhado para outro provedor.
        // O modelo explicitamente selecionado no chat tem precedência sobre
        // o modelo padrão do agente. Chats de agentes continuam usando o
        // modelo do agente quando não possuem seleção própria.
        $requestedModel = $chat?->selected_model ?? $agent->model ?? null;
        $modelSource = $chat?->selected_model ? 'chat_selected' : 'agent_model';
        $model = AiModelCatalog::normalize($requestedModel);

        if ($requestedModel !== null && $requestedModel !== $model) {
            Log::warning('Modelo de conversação legado normalizado antes da criação do provedor', [
                'agent_id' => $agent->uuid,
                'chat_id' => $chat?->uuid,
                'requested_model' => $requestedModel,
                'normalized_model' => $model,
            ]);
        }

        // Verifica se deve usar API do sistema
        $useSystemApi = self::shouldUseSystemApi();

        if ($useSystemApi) {
            Log::info('Usando API key do sistema para agente', [
                'agent_id' => $agent->uuid,
                'user_id' => $agent->user_id
            ]);

            // Para API do sistema, sempre usa OpenAI (por enquanto)
            // Passa true para usar API do sistema
            return new OpenAiChatService(true, $model);
        }

        // Os IDs normalizados acima pertencem exclusivamente à OpenAI. A
        // chave do usuário só é usada quando for uma chave OpenAI ativa;
        // qualquer outra API configurada não pode alterar o provedor desta
        // conversação.
        $hasOpenAiApi = self::userHasApiForPlatform($agent->user, 'openai');

        Log::info('Provedor OpenAI selecionado para modelo de conversação', [
            'agent_id' => $agent->uuid,
            'user_id' => $agent->user_id,
            'model' => $model,
            'source' => $modelSource,
            'credential_source' => $hasOpenAiApi ? 'user_api' : 'system_api',
        ]);

        return new OpenAiChatService(!$hasOpenAiApi, $model);
    }

    /**
     * Verifica se o usuário tem API configurada para uma plataforma específica
     */
    private static function userHasApiForPlatform($user, string $platformSlug): bool
    {
        if (!$user) {
            return false;
        }

        return $user->userApis()
            ->where('platform_slug', $platformSlug)
            ->where('is_active', true)
            ->exists();
    }

    /**
     * Retorna lista de plataformas suportadas
     *
     * @return array
     */
    public static function getSupportedPlatforms(): array
    {
        return array_keys(self::$providerMap);
    }

    /**
     * Verifica se uma plataforma é suportada
     *
     * @param string $platformSlug
     * @return bool
     */
    public static function isPlatformSupported(string $platformSlug): bool
    {
        return isset(self::$providerMap[$platformSlug]);
    }

    /**
     * Obtém o nome da classe do provedor para uma plataforma
     *
     * @param string $platformSlug
     * @return string|null
     */
    public static function getProviderClass(string $platformSlug): ?string
    {
        return self::$providerMap[$platformSlug] ?? null;
    }

    /**
     * Obtém todos os modelos disponíveis de todos os provedores
     *
     * @return array
     */
    public static function getAllModels(): array
    {
        $models = [];

        foreach (AiModelCatalog::all() as $modelKey => $modelName) {
            $models[$modelKey] = [
                'name' => $modelName,
                'provider' => 'openai',
                'provider_class' => OpenAiChatService::class,
            ];
        }

        return $models;
    }

    /**
     * Obtém modelos disponíveis para um usuário específico (baseado nas APIs configuradas)
     *
     * @param $user
     * @return array
     */
    public static function getAvailableModelsForUser($user): array
    {
        if (!$user) {
            return [];
        }

        return self::getAllModels();
    }

    /**
     * Valida se um modelo é suportado por algum provedor
     *
     * @param string $model
     * @return array|null Retorna informações do provedor se encontrado, null caso contrário
     */
    public static function validateModelGlobally(string $model): ?array
    {
        if (!AiModelCatalog::isAllowed($model)) {
            return null;
        }

        return [
            'platform' => 'openai',
            'provider_class' => OpenAiChatService::class,
            'models' => AiModelCatalog::all(),
        ];
    }
}
