<?php

namespace App\Http\Controllers\Knowledge\Context;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Knowledge\Services\ContextService;
use App\Http\Controllers\Knowledge\Services\AIProviderFactory;
use App\Http\Controllers\Knowledge\Services\AsyncMultimodalProcessor;
use App\Http\Controllers\Knowledge\Services\Caching\MemoryCacheService;
use App\Http\Controllers\Knowledge\Services\SemanticSearchService;
use App\Http\Controllers\Knowledge\Services\LeadQualificationService;
use App\Http\Controllers\Knowledge\Services\SystemPromptKernelService;
use App\Http\Controllers\Knowledge\Services\MemoryFetchingService;
use App\Http\Controllers\Knowledge\Services\EpisodicMemoryService;
use App\Http\Controllers\Knowledge\Services\SelfReflectionService;
use App\Http\Controllers\Knowledge\Services\PostProcessorService;
use App\Http\Controllers\Knowledge\Services\MoodTrackingService;
use App\Http\Controllers\Knowledge\Services\MemoryCompressionService;
use App\Http\Controllers\Knowledge\Services\PersonaDriftCorrectionService;
use App\Http\Controllers\Knowledge\Services\ObservabilityService;
use App\Services\WhatsappMessageFormatter;
use App\Models\Chats;
use App\Models\Agents;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Controller Maestro (Orchestrator).
 *
 * Responsabilidade: Orquestrar a lógica de negócio para gerar uma resposta inteligente
 * usando Chat Completions API com sistema de memória contextual.
 * Atua como uma fachada para o módulo de conhecimento, sendo invocado por outros
 * controllers (ex: ZApiWebhookController) e não diretamente por rotas.
 */
class ContextController extends Controller
{
    private $contextService;
    private $systemPromptKernel;
    private $memoryFetching;
    private $episodicMemory;
    private $selfReflection;
    private $postProcessor;
    private $moodTracking;
    private $memoryCompression;
    private $personaDrift;
    private $observability;
    private $memoryCache;
    private $semanticSearch;
    private $asyncMultimodalProcessor;
    private $whatsappMessageFormatter;

    public function __construct(
        ContextService $contextService,
        SystemPromptKernelService $systemPromptKernel,
        MemoryFetchingService $memoryFetching,
        EpisodicMemoryService $episodicMemory,
        SelfReflectionService $selfReflection,
        PostProcessorService $postProcessor,
        MoodTrackingService $moodTracking,
        MemoryCompressionService $memoryCompression,
        PersonaDriftCorrectionService $personaDrift,
        ObservabilityService $observability,
        MemoryCacheService $memoryCache,
        SemanticSearchService $semanticSearch,
        AsyncMultimodalProcessor $asyncMultimodalProcessor,
        WhatsappMessageFormatter $whatsappMessageFormatter
    ) {
        $this->contextService = $contextService;
        $this->systemPromptKernel = $systemPromptKernel;
        $this->memoryFetching = $memoryFetching;
        $this->episodicMemory = $episodicMemory;
        $this->selfReflection = $selfReflection;
        $this->postProcessor = $postProcessor;
        $this->moodTracking = $moodTracking;
        $this->memoryCompression = $memoryCompression;
        $this->personaDrift = $personaDrift;
        $this->observability = $observability;
        $this->memoryCache = $memoryCache;
        $this->semanticSearch = $semanticSearch;
        $this->asyncMultimodalProcessor = $asyncMultimodalProcessor;
        $this->whatsappMessageFormatter = $whatsappMessageFormatter;
    }

    /**
     * Obtém o provedor de IA correto baseado no agente
     */
    private function getAIProvider($agent, ?Chats $chat = null)
    {
        return AIProviderFactory::create($agent, $chat);
    }

    /**
     * Verifica se o usuário tem créditos suficientes para gerar uma resposta (versão com lock pessimista)
     * Usa bloqueio pessimista para evitar condições de corrida entre múltiplas requisições simultâneas
     *
     * ✅ LÓGICA CORRETA:
     * - Quando usa API do sistema (usuário sem API ou config ativa) → DEVE verificar créditos
     * - Quando usa API do usuário → NÃO verifica créditos (usuário paga diretamente ao provedor)
     */
    private function hasEnoughCreditsForResponseWithLock(Agents $agent, int $estimatedTokens = 1000): bool
    {
        // Verificar se usuário tem APIs ativas
        $userHasActiveApis = \App\Http\Controllers\Knowledge\Services\AIProviderFactory::userHasActiveApis($agent);

        // ✅ Se usuário TEM API ativa, NÃO verificar créditos (usa API do usuário)
        if ($userHasActiveApis) {
            Log::info('Verificação de créditos pulada - usando API do usuário', [
                'agent_id' => $agent->uuid,
                'user_has_active_apis' => true,
                'reason' => 'using_user_api'
            ]);
            return true;
        }

        // ✅ Se usuário NÃO tem API ativa, usa API do sistema → DEVE verificar créditos

        $user = $agent->user;

        if (!$user) {
            return false;
        }

        $requiredCredits = $estimatedTokens / 1000;

        try {
            // Usar lock pessimista para evitar condições de corrida
            // Importar o modelo User se não estiver disponível
            $userModel = app(\App\Models\User::class);
            $userWithLock = $userModel::where('uuid', $user->uuid)
                ->lockForUpdate()
                ->first();

            if (!$userWithLock) {
                Log::warning('Usuário não encontrado para verificação de créditos com lock', [
                    'user_uuid' => $user->uuid
                ]);
                return false;
            }

            $totalCredits = ($userWithLock->credits ?? 0) + ($userWithLock->combo ?? 0);

            return $totalCredits >= $requiredCredits;
        } catch (\Exception $e) {
            Log::error('Erro ao verificar créditos com lock pessimista', [
                'user_id' => $user->uuid,
                'estimated_tokens' => $estimatedTokens,
                'required_credits' => $requiredCredits,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    /**
     * Processa uma resposta completa com controle transacional de créditos
     * Garante que tanto a geração da resposta quanto a debitação ocorram atomicamente
     *
     * @param Chats $chat
     * @param Agents $agent
     * @param string $userMessage
     * @param array $options
     * @return array
     */
    public function generateResponseWithCreditTransaction(Chats $chat, Agents $agent, string $userMessage, array $options = []): array
    {
        Log::info('ContextController: Iniciando geração de resposta com controle transacional de créditos', [
            'agent_id' => $agent->uuid,
            'agent_personal' => $agent->is_personal,
            'chat_id' => $chat->uuid,
            'message_length' => strlen($userMessage),
            'user_message' => $userMessage
        ]);

        // Usar transação para garantir atomicidade entre verificação de créditos e processamento
        return DB::transaction(function () use ($chat, $agent, $userMessage, $options) {
            // Verificar créditos com lock pessimista dentro da transação
            if (!$this->hasEnoughCreditsForResponseWithLock($agent)) {
                Log::warning('ContextController: Créditos insuficientes na transação atômica', [
                    'agent_id' => $agent->uuid,
                    'chat_id' => $chat->uuid,
                    'user_credits' => $agent->user->credits ?? 0,
                    'user_combo' => $agent->user->combo ?? 0
                ]);

                return [
                    'message' => 'Desculpe, no momento não estamos atendendo. Tente novamente em outro horário. Agradecemos a compreensão.',
                    'tokens' => 0,
                    'error' => 'crédito insuficiente para gerar resposta'
                ];
            }

            // Se chegou aqui, temos créditos suficientes - processar normalmente
            $result = $this->generateResponseInternal($chat, $agent, $userMessage, $options);

            // Se a resposta foi gerada com sucesso e consumiu tokens, debitar créditos
            $tokensUsed = $result['tokens'] ?? 0;
            $userHasActiveApis = \App\Http\Controllers\Knowledge\Services\AIProviderFactory::userHasActiveApis($agent);

            // ✅ LÓGICA CORRETA:
            // - Se usuário TEM API ativa → usa API do usuário → NÃO debita créditos
            // - Se usuário NÃO tem API ativa → usa API do sistema → DEVE debitar créditos
            if ($tokensUsed > 0 && empty($result['error']) && !$userHasActiveApis) {
                // Usuário sem API → usa API do sistema → DEVE debitar créditos
                $creditDeductionSuccess = $this->deductUserCredits($agent->user, $tokensUsed);
                if (!$creditDeductionSuccess) {
                    // Se não conseguiu debitar créditos, fazer rollback da transação
                    throw new \Exception('Falha ao debitar créditos após gerar resposta');
                }

                Log::info('Transação completa: resposta gerada com API do sistema e créditos debitados', [
                    'chat_id' => $chat->uuid,
                    'agent_id' => $agent->uuid,
                    'tokens_used' => $tokensUsed,
                    'credit_deduction_success' => $creditDeductionSuccess,
                    'reason' => 'using_system_api'
                ]);
            } elseif ($userHasActiveApis && $tokensUsed > 0 && empty($result['error'])) {
                // Usuário com API → usa API do usuário → NÃO debita créditos
                Log::info('Transação completa: resposta gerada com API do usuário (créditos não debitados)', [
                    'chat_id' => $chat->uuid,
                    'agent_id' => $agent->uuid,
                    'tokens_used' => $tokensUsed,
                    'user_has_active_apis' => true,
                    'reason' => 'using_user_api'
                ]);
            }

            return $result;
        });
    }

    /**
     * Método interno para geração de resposta (extraído do generateResponse original)
     * Usado pelo método transacional para evitar duplicação de código
     */
    private function generateResponseInternal(Chats $chat, Agents $agent, string $userMessage, array $options = []): array
    {
        $multimodalResults = [];

        try {
            // ===== MULTIMODAL PROCESSING =====
            if (!empty($options['multimodal_files'])) {
                // ✅ Validar capacidades do provedor ANTES de processar
                $aiProvider = $this->getAIProvider($agent, $chat);
                $validationResult = $this->validateProviderMultimodalCapabilities($aiProvider, $options['multimodal_files'], $agent);

                if (!$validationResult['valid']) {
                    Log::warning('Provedor não suporta tipo de mídia solicitado (generateResponseInternal)', [
                        'agent_id' => $agent->uuid,
                        'provider' => $aiProvider->getCapabilities()['provider'] ?? 'unknown',
                        'unsupported_types' => $validationResult['unsupported_types']
                    ]);

                    return [
                        'message' => $validationResult['error_message'],
                        'tokens' => 0,
                        'error' => 'multimodal_not_supported',
                        'unsupported_types' => $validationResult['unsupported_types']
                    ];
                }

                $multimodalResults = $this->processMultimodalFiles($chat, $agent, $options['multimodal_files']);
            }

            // ===== PRE-PROCESSOR =====
            $currentMood = $this->moodTracking->trackMood($chat, $userMessage);
            $intentData = $this->memoryFetching->extractIntentForMemory($userMessage);

            // ===== MEMORY FETCH =====
            $relevantEpisodes = $this->getCachedOrFetchEpisodes($chat, $userMessage);
            $multimodalContext = '';
            if (!empty($multimodalResults)) {
                $multimodalContext = $this->buildMultimodalContext($multimodalResults, $userMessage);
            }
            $memoryContext = $this->getCachedOrFetchMemoryContext($chat, $userMessage, $relevantEpisodes);

            // ===== SYSTEM PROMPT KERNEL =====
            // 🆕 Verificar se é a primeira interação para otimizar consumo de tokens
            $session = $this->contextService->getSession($chat);
            $isFirstInteraction = $this->isFirstInteraction($session);
            $systemPrompt = $this->systemPromptKernel->buildKernel($agent, $isFirstInteraction);
            if (!empty($memoryContext)) {
                $systemPrompt .= "\n\n" . $memoryContext;
            }
            if (!empty($multimodalContext)) {
                $systemPrompt .= "\n\n" . $multimodalContext;
            }

            // ===== MESSAGE BUILDER =====
            $messages = $this->contextService->buildMessagesForChatCompletions($chat, $systemPrompt, $userMessage);

            // ===== CHAT COMPLETIONS API =====
            $aiProvider = $this->getAIProvider($agent, $chat);

            // Remover multimodal_files das opções (já foram processados e incluídos no contexto)
            $apiOptions = $options;
            unset($apiOptions['multimodal_files']);

            $response = $aiProvider->getCompletion($messages, $agent, $apiOptions);
            $rawResponse = $response['message'];

            // ===== POST-PROCESSOR =====
            $processedResponse = $this->postProcessor->process($rawResponse, [
                'max_characters' => $agent->max_characters ?? 500,
                'forbidden_terms' => $this->getForbiddenTerms($agent)
            ]);

            $finalResponse = $this->moodTracking->adjustToneByMood($processedResponse, $currentMood);

            // ===== PERSONA DRIFT CORRECTION =====
            $session = $this->contextService->getSession($chat);
            $responseCount = $this->getResponseCount($session);
            $personaCheck = $this->personaDrift->checkAndCorrect($agent, $finalResponse, $responseCount);

            if ($personaCheck['needs_correction'] && !empty($personaCheck['corrected_response'])) {
                $finalResponse = $personaCheck['corrected_response'];
                Log::info('Persona drift corrigido', [
                    'chat_id' => $chat->uuid,
                    'drift_score' => $personaCheck['drift_score']
                ]);
            }

            // Última etapa: garante que ajustes de tom/persona também não
            // reintroduzam Markdown ou aspas na mensagem enviada.
            $finalResponse = $this->whatsappMessageFormatter->format($finalResponse);

            // ===== SELF-REFLECTION =====
            $reflection = [];
            $enableSelfReflection = $options['enable_self_reflection'] ?? config('knowledge.self_reflection.enabled', false);

            if ($enableSelfReflection && $this->shouldPerformHeavyOperations($agent)) {
                $reflection = $this->selfReflection->reflect(
                    $agent,
                    $userMessage,
                    $finalResponse,
                    $relevantEpisodes
                );
            } elseif (config('knowledge.self_reflection.use_heuristic_fallback', true)) {
                $reflection = $this->basicReflection($userMessage, $finalResponse);
            } else {
                $reflection = [
                    'store' => true,
                    'memory' => [
                        'summary' => substr($userMessage, 0, 100) . '...',
                        'intent' => 'general'
                    ],
                    'should_improve' => false
                ];
            }

            // ===== MEMORY WRITER =====
            $this->contextService->appendInteraction($chat, 'user', $userMessage, [
                'intent' => $intentData['intent'],
                'emotion' => $currentMood
            ]);
            $this->contextService->appendInteraction($chat, 'assistant', $finalResponse);

            if ($reflection['store'] && !empty($reflection['memory'])) {
                $this->episodicMemory->createEpisode(
                    $chat,
                    $userMessage,
                    $finalResponse,
                    $reflection['memory']
                );
            } else {
                $this->episodicMemory->createEpisode(
                    $chat,
                    $userMessage,
                    $finalResponse,
                    [
                        'intent' => $intentData['intent'],
                        'emotion' => $currentMood
                    ]
                );
            }

            // ===== MEMORY COMPRESSION =====
            if ($session) {
                $compressionApplied = $this->memoryCompression->compressIfNeeded($session);
            } else {
                $compressionApplied = false;
            }

            // ===== OBSERVABILITY & TRACING =====
            $metrics = [
                'model' => $response['model'] ?? null,
                'tokens' => $response['tokens'] ?? 0,
                'prompt_tokens' => $response['prompt_tokens'] ?? 0,
                'completion_tokens' => $response['completion_tokens'] ?? 0,
                'intent' => $intentData['intent'],
                'mood' => $currentMood,
                'episodes_used' => $relevantEpisodes,
                'reflection' => $reflection,
                'compression_applied' => $compressionApplied,
                'persona_drift_score' => $personaCheck['drift_score'] ?? null,
            ];

            $this->observability->logInteraction($chat, $agent, $metrics);

            if (!empty($relevantEpisodes)) {
                $this->observability->logEpisodesUsed($chat, $relevantEpisodes);
            }

            $this->observability->checkAnomalies($chat, $agent, $metrics);

            // ===== RETORNAR RESPOSTA =====
            return [
                'multimodal_processed' => $multimodalResults,
                'message' => $finalResponse,
                'tokens' => $response['tokens'] ?? 0,
                'prompt_tokens' => $response['prompt_tokens'] ?? 0,
                'completion_tokens' => $response['completion_tokens'] ?? 0,
                'model' => $response['model'] ?? null,
                'reflection' => $reflection,
                'mood' => $currentMood,
                'persona_drift_score' => $personaCheck['drift_score'] ?? null,
                'metrics' => $this->observability->generateMetricsSummary($chat, $metrics)
            ];
        } catch (\Exception $e) {
            Log::error("Erro ao gerar resposta interna: {$e->getMessage()}", [
                'chat_id' => $chat->uuid ?? null,
                'agent_id' => $agent->uuid ?? null,
                'trace' => $e->getTraceAsString()
            ]);
            return [
                'message' => 'Desculpe, no momento não estamos atendendo. Tente novamente em outro horário. Agradecemos a compreensão.',
                'tokens' => 0,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Orquestra a geração de uma resposta do agente usando Chat Completions + Memória.
     *
     * Fluxo v2.0:
     * User Input → Multimodal Processing → Pre-Processor → Intent Extractor → Memory Fetch
     * → Message Builder ← Context Synthesizer → Chat Completions API
     * → Post-Processor → Memory Writer → Long-Term Store
     *
     * @param Chats $chat O objeto da conversa ativa
     * @param Agents $agent O agente configurado
     * @param string $userMessage Mensagem do usuário
     * @param array $options Opções adicionais (multimodal_files, max_completion_tokens, reasoning_effort, etc)
     * @return array ['message' => string, 'tokens' => int, 'multimodal_processed' => array]
     */
    public function generateResponse(Chats $chat, Agents $agent, string $userMessage, array $options = []): array
    {
        Log::info('ContextController: Iniciando geração de resposta', [
            'agent_id' => $agent->uuid,
            'agent_personal' => $agent->is_personal,
            'chat_id' => $chat->uuid,
            'message_length' => strlen($userMessage),
            'user_message' => $userMessage
        ]);

        // ✅ Verificar créditos apenas se usuário NÃO tem APIs ativas (usa API do sistema)
        // Se usuário tem API ativa, não verifica créditos (usa API do usuário)
        $userHasActiveApis = \App\Http\Controllers\Knowledge\Services\AIProviderFactory::userHasActiveApis($agent);

        if (!$userHasActiveApis && !$this->hasEnoughCreditsForResponseWithLock($agent)) {
            Log::warning('ContextController: Créditos insuficientes - usuário sem API ativa (usa API do sistema)', [
                'agent_id' => $agent->uuid,
                'user_credits' => $agent->user->credits ?? 0,
                'user_combo' => $agent->user->combo ?? 0,
                'user_uuid' => $agent->user->uuid ?? null,
                'user_has_active_apis' => false,
                'reason' => 'insufficient_credits_for_system_api'
            ]);
            return [
                'message' => 'Desculpe, no momento não estamos atendendo. Tente novamente em outro horário. Agradecemos a compreensão.',
                'tokens' => 0,
                'error' => 'crédito insuficiente para gerar resposta'
            ];
        }

        $multimodalResults = [];

        try {
            // ===== MULTIMODAL PROCESSING =====
            // 0. Validar capacidades do provedor ANTES de processar arquivos multimodais
            if (!empty($options['multimodal_files'])) {
                $aiProvider = $this->getAIProvider($agent, $chat);
                $validationResult = $this->validateProviderMultimodalCapabilities($aiProvider, $options['multimodal_files'], $agent);

                if (!$validationResult['valid']) {
                    // Provedor não suporta algum tipo de mídia - retornar erro claro
                    Log::warning('Provedor não suporta tipo de mídia solicitado', [
                        'agent_id' => $agent->uuid,
                        'provider' => $aiProvider->getCapabilities()['provider'] ?? 'unknown',
                        'unsupported_types' => $validationResult['unsupported_types'],
                        'error_message' => $validationResult['error_message']
                    ]);

                    return [
                        'message' => $validationResult['error_message'],
                        'tokens' => 0,
                        'error' => 'multimodal_not_supported',
                        'unsupported_types' => $validationResult['unsupported_types']
                    ];
                }

                // Validação passou - processar arquivos
                $multimodalResults = $this->processMultimodalFiles($chat, $agent, $options['multimodal_files']);
            }

            // ===== PRE-PROCESSOR =====
            // 1. Rastrear humor/emoção do usuário
            $currentMood = $this->moodTracking->trackMood($chat, $userMessage);

            // 2. Extrair intenção para busca de memória
            $intentData = $this->memoryFetching->extractIntentForMemory($userMessage);

            // ===== MEMORY FETCH =====
            // 3. Buscar memórias relevantes (episódios) - com cache inteligente
            $relevantEpisodes = $this->getCachedOrFetchEpisodes($chat, $userMessage);

            // 🆕 Verificar se provedor suporta vision ANTES de construir contexto multimodal
            $imagesForVision = [];
            $useVisionMode = false;

            if (!empty($multimodalResults)) {
                $aiProvider = $this->getAIProvider($agent, $chat);

                // ✅ SOLUÇÃO GENÉRICA: Usar apenas supportsImages() do provedor
                // Cada provedor define se suporta vision através do método supportsImages()
                // (OpenAI, Gemini, Claude, etc. já implementam corretamente)
                $useVisionMode = $aiProvider->supportsImages();

                if ($useVisionMode) {
                    // Extrair caminhos de imagens dos resultados multimodais
                    foreach ($multimodalResults as $result) {
                        if (($result['metadata']['type'] ?? '') === 'image') {
                            // Tentar obter caminho do arquivo original
                            $imagePath = $result['metadata']['file_path'] ?? null;

                            // Se não encontrou no metadata, buscar no array original de arquivos
                            if (!$imagePath && !empty($options['multimodal_files'])) {
                                foreach ($options['multimodal_files'] as $file) {
                                    if (($file['type'] ?? '') === 'image' && !empty($file['path'])) {
                                        $imagePath = $file['path'];
                                        break;
                                    }
                                }
                            }

                            if ($imagePath && file_exists($imagePath)) {
                                $imagesForVision[] = $imagePath;
                            } else {
                                Log::warning('Caminho de imagem não encontrado para vision', [
                                    'result_metadata' => $result['metadata'] ?? [],
                                    'has_options_files' => !empty($options['multimodal_files'])
                                ]);
                            }
                        }
                    }

                    Log::info('Usando modo vision - imagens serão enviadas diretamente para o modelo', [
                        'agent_id' => $agent->uuid,
                        'model' => $agent->model ?? 'unknown',
                        'provider' => $aiProvider->getCapabilities()['provider'] ?? 'unknown',
                        'images_count' => count($imagesForVision),
                        'use_vision' => $useVisionMode
                    ]);
                } else {
                    Log::info('Provedor não suporta vision ou não há imagens - usando análise prévia', [
                        'agent_id' => $agent->uuid,
                        'model' => $agent->model ?? 'unknown',
                        'provider' => $aiProvider->getCapabilities()['provider'] ?? 'unknown',
                        'supports_images' => $aiProvider->supportsImages(),
                        'has_multimodal_results' => !empty($multimodalResults)
                    ]);
                }
            }

            // 4. Buscar contexto multimodal relevante se houver arquivos processados
            // 🆕 Apenas construir contexto textual se NÃO estiver usando vision mode
            $multimodalContext = '';
            if (!empty($multimodalResults) && !$useVisionMode) {
                $multimodalContext = $this->buildMultimodalContext($multimodalResults, $userMessage);
            }

            // 5. Buscar contexto de memória tradicional - com cache
            $memoryContext = $this->getCachedOrFetchMemoryContext($chat, $userMessage, $relevantEpisodes);

            // ===== SYSTEM PROMPT KERNEL =====
            // ✅ NOVA LÓGICA: Consolidar training na primeira interação
            $session = $this->contextService->getSession($chat);

            if ($this->isFirstInteraction($session)) {
                // ✅ PRIMEIRA INTERAÇÃO: Consolidar training na knowledge_base
                Log::info('Primeira interação detectada - consolidando training na knowledge_base', [
                    'chat_id' => $chat->uuid,
                    'agent_id' => $agent->uuid
                ]);

                $trainingCondensado = $this->systemPromptKernel->buildCondensedTrainingForKnowledgeBase($agent);

                // Salvar na knowledge_base
                $kb = $session->knowledge_base ?? [];
                if (is_string($kb)) {
                    $decoded = json_decode($kb, true);
                    $kb = json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? $decoded : [];
                }
                $kb['training_condensado'] = $trainingCondensado;
                $session->knowledge_base = $kb;
                $this->contextService->getRepository()->save($session);

                // Usar training condensado + memory guidelines
                $memoryGuidelines = "## Como Interpretar Memórias\n\n";
                $memoryGuidelines .= "Quando receber contexto de memórias passadas:\n";
                $memoryGuidelines .= "- Use-as como referência, não como verdade absoluta\n";
                $memoryGuidelines .= "- Se houver contradição com a conversa atual, priorize o contexto atual\n";
                $memoryGuidelines .= "- Não mencione explicitamente que está 'lembrando' de algo\n";
                $memoryGuidelines .= "- Integre informações de memória de forma natural na conversa\n";
                $memoryGuidelines .= "- Se a memória não for relevante ao tópico atual, ignore-a silenciosamente\n";

                $systemPrompt = $trainingCondensado . "\n\n" . $memoryGuidelines;

                Log::info('Training consolidado na knowledge_base (primeira interação)', [
                    'chat_id' => $chat->uuid,
                    'agent_id' => $agent->uuid,
                    'condensed_length' => strlen($trainingCondensado),
                    'total_prompt_length' => strlen($systemPrompt)
                ]);
            } else {
                // ✅ INTERAÇÕES SUBSEQUENTES: Usar training da knowledge_base
                $systemPrompt = $this->systemPromptKernel->buildKernel($agent, false, $session);

                Log::debug('Usando training da knowledge_base (interação subsequente)', [
                    'chat_id' => $chat->uuid,
                    'agent_id' => $agent->uuid,
                    'prompt_length' => strlen($systemPrompt)
                ]);
            }

            // Adiciona contexto de memória ao system prompt se houver
            if (!empty($memoryContext)) {
                $systemPrompt .= "\n\n" . $memoryContext;
            }

            // Adiciona contexto multimodal se houver (apenas se NÃO estiver usando vision mode)
            // Quando usando vision, a imagem é enviada diretamente, não precisa de contexto textual
            if (!empty($multimodalContext)) {
                $systemPrompt .= "\n\n" . $multimodalContext;
            }

            // ===== MESSAGE BUILDER =====
            // 5. Ajustar userMessage quando há imagens no vision mode
            // 🆕 Quando a mensagem é apenas "[Imagem]", fazer o agente contextualizar em vez de apenas descrever
            $adjustedUserMessage = $userMessage;
            if (!empty($imagesForVision)) {
                // Se a mensagem é apenas "[Imagem]" ou está vazia, usar mensagem natural que incentive contextualização
                $normalizedMessage = strtolower(trim($userMessage));
                if (empty($normalizedMessage) || in_array($normalizedMessage, ['[imagem]', '[image]', 'imagem', 'image'])) {
                    // Mensagem natural que faz o agente pensar no contexto do negócio
                    $adjustedUserMessage = "O que você acha desta imagem no contexto da nossa conversa e interação?";
                }
                // Se há texto do usuário, manter como está (o agente já tem contexto do system prompt)
            }

            // Construir array de mensagens com histórico e memória
            $messages = $this->contextService->buildMessagesForChatCompletions($chat, $systemPrompt, $adjustedUserMessage, $imagesForVision);

            // ===== CHAT COMPLETIONS API =====
            // 6. Chamar Chat Completions API
            $aiProvider = $this->getAIProvider($agent, $chat);

            // Remover multimodal_files das opções (já foram processados e incluídos no contexto)
            $apiOptions = $options;
            unset($apiOptions['multimodal_files']);

            // 🆕 Log antes de chamar a API
            Log::info('Chamando API para gerar resposta', [
                'agent_id' => $agent->uuid,
                'has_multimodal_context' => !empty($multimodalContext),
                'multimodal_context_length' => strlen($multimodalContext),
                'user_message' => $userMessage,
                'messages_count' => count($messages)
            ]);

            $response = $aiProvider->getCompletion($messages, $agent, $apiOptions);
            $rawResponse = $response['message'];

            // 🆕 Log da resposta bruta da API
            Log::info('Resposta bruta recebida da API', [
                'agent_id' => $agent->uuid,
                'response_length' => strlen($rawResponse),
                'response_preview' => substr($rawResponse, 0, 200),
                'tokens_used' => $response['tokens'] ?? 0,
                'has_error_key' => isset($response['error'])
            ]);

            // ===== POST-PROCESSOR =====
            // 7. Processar e sanitizar resposta
            $processedResponse = $this->postProcessor->process($rawResponse, [
                'max_characters' => $agent->max_characters ?? 500,
                'forbidden_terms' => $this->getForbiddenTerms($agent) // Termos proibidos do agente
            ]);

            // Ajustar tom baseado no humor (implementação real)
            $finalResponse = $this->moodTracking->adjustToneByMood($processedResponse, $currentMood);

            // ===== PERSONA DRIFT CORRECTION =====
            // 7.5. Verificar e corrigir deriva de persona (a cada X respostas)
            $session = $this->contextService->getSession($chat);
            $responseCount = $this->getResponseCount($session);
            $personaCheck = $this->personaDrift->checkAndCorrect($agent, $finalResponse, $responseCount);

            // Se persona drift detectado, usa resposta corrigida
            if ($personaCheck['needs_correction'] && !empty($personaCheck['corrected_response'])) {
                $finalResponse = $personaCheck['corrected_response'];
                Log::info('Persona drift corrigido', [
                    'chat_id' => $chat->uuid,
                    'drift_score' => $personaCheck['drift_score']
                ]);
            }

            // Última etapa: garante que ajustes de tom/persona também não
            // reintroduzam Markdown ou aspas na mensagem enviada.
            $finalResponse = $this->whatsappMessageFormatter->format($finalResponse);

            // ===== SELF-REFLECTION =====
            // 8. Auto-reflexão: avaliar se resposta está boa e se deve gravar memória
            // OPCIONAL: Só executa se configurado e não for resposta muito rápida
            $reflection = [];
            $enableSelfReflection = $options['enable_self_reflection'] ?? config('knowledge.self_reflection.enabled', false);

            if ($enableSelfReflection && $this->shouldPerformHeavyOperations($agent)) {
                $reflection = $this->selfReflection->reflect(
                    $agent,
                    $userMessage,
                    $finalResponse,
                    $relevantEpisodes
                );
            } elseif (config('knowledge.self_reflection.use_heuristic_fallback', true)) {
                // Reflexão básica baseada em heurísticas (muito mais rápida)
                $reflection = $this->basicReflection($userMessage, $finalResponse);
            } else {
                // Sem reflexão - assume que deve armazenar
                $reflection = [
                    'store' => true,
                    'memory' => [
                        'summary' => substr($userMessage, 0, 100) . '...',
                        'intent' => 'general'
                    ],
                    'should_improve' => false
                ];
            }

            // ===== MEMORY WRITER =====
            // 9. Registrar interações na memória de curto prazo (working memory)
            $this->contextService->appendInteraction($chat, 'user', $userMessage, [
                'intent' => $intentData['intent'],
                'emotion' => $currentMood
            ]);
            $this->contextService->appendInteraction($chat, 'assistant', $finalResponse);

            // 10. Criar episódio na memória de longo prazo se auto-reflexão indicar
            if ($reflection['store'] && !empty($reflection['memory'])) {
                $this->episodicMemory->createEpisode(
                    $chat,
                    $userMessage,
                    $finalResponse,
                    $reflection['memory']
                );
            } else {
                // Cria episódio básico mesmo sem auto-reflexão positiva (para histórico)
                $this->episodicMemory->createEpisode(
                    $chat,
                    $userMessage,
                    $finalResponse,
                    [
                        'intent' => $intentData['intent'],
                        'emotion' => $currentMood
                    ]
                );
            }

            // ===== MEMORY COMPRESSION =====
            // 11. Verificar se precisa comprimir histórico
            if ($session) {
                $compressionApplied = $this->memoryCompression->compressIfNeeded($session);
            } else {
                $compressionApplied = false;
            }

            // ===== OBSERVABILITY & TRACING =====
            // 12. Registrar métricas e tracing
            $metrics = [
                'model' => $response['model'] ?? null,
                'tokens' => $response['tokens'] ?? 0,
                'prompt_tokens' => $response['prompt_tokens'] ?? 0,
                'completion_tokens' => $response['completion_tokens'] ?? 0,
                'intent' => $intentData['intent'],
                'mood' => $currentMood,
                'episodes_used' => $relevantEpisodes,
                'reflection' => $reflection,
                'compression_applied' => $compressionApplied,
                'persona_drift_score' => $personaCheck['drift_score'] ?? null,
            ];

            // Log interação completa
            $this->observability->logInteraction($chat, $agent, $metrics);

            // Log episódios usados
            if (!empty($relevantEpisodes)) {
                $this->observability->logEpisodesUsed($chat, $relevantEpisodes);
            }

            // Verificar anomalias e gerar alertas
            $this->observability->checkAnomalies($chat, $agent, $metrics);

            // ===== CREDIT DEDUCTION =====
            // 13. Debitar créditos do usuário baseado nos tokens utilizados
            // ✅ LÓGICA CORRETA:
            // - Se usuário TEM API ativa → usa API do usuário → NÃO debita créditos
            // - Se usuário NÃO tem API ativa → usa API do sistema → DEVE debitar créditos
            $tokensUsed = $response['tokens'] ?? 0;
            $userHasActiveApis = \App\Http\Controllers\Knowledge\Services\AIProviderFactory::userHasActiveApis($agent);

            if ($tokensUsed > 0 && !$userHasActiveApis) {
                // Usuário sem API → usa API do sistema → DEVE debitar créditos
                $creditDeductionSuccess = $this->deductUserCredits($agent->user, $tokensUsed);
                if (!$creditDeductionSuccess) {
                    Log::error('Falha ao debitar créditos após gerar resposta com API do sistema', [
                        'agent_id' => $agent->uuid,
                        'chat_id' => $chat->uuid,
                        'user_id' => $agent->user->uuid ?? null,
                        'tokens_used' => $tokensUsed,
                        'reason' => 'using_system_api'
                    ]);
                    // Mesmo com falha na dedução, retornar a resposta pois ela já foi gerada
                    // Mas marcar no log para atenção administrativa
                } else {
                    Log::info('Créditos debitados - resposta gerada com API do sistema', [
                        'agent_id' => $agent->uuid,
                        'chat_id' => $chat->uuid,
                        'tokens_used' => $tokensUsed,
                        'reason' => 'using_system_api'
                    ]);
                }
            } elseif ($userHasActiveApis && $tokensUsed > 0) {
                // Usuário com API → usa API do usuário → NÃO debita créditos
                Log::info('Resposta gerada com API do usuário - créditos não debitados', [
                    'agent_id' => $agent->uuid,
                    'chat_id' => $chat->uuid,
                    'tokens_used' => $tokensUsed,
                    'user_has_active_apis' => true,
                    'reason' => 'using_user_api'
                ]);
            }

            // ===== RETORNAR RESPOSTA =====
            $result = [
                'multimodal_processed' => $multimodalResults,
                'message' => $finalResponse . "\u{200B}",
                'tokens' => $response['tokens'] ?? 0,
                'prompt_tokens' => $response['prompt_tokens'] ?? 0,
                'completion_tokens' => $response['completion_tokens'] ?? 0,
                'model' => $response['model'] ?? null,
                'reflection' => $reflection,
                'mood' => $currentMood,
                'persona_drift_score' => $personaCheck['drift_score'] ?? null,
                'metrics' => $this->observability->generateMetricsSummary($chat, $metrics)
            ];

            return $result;
        } catch (\Exception $e) {
            Log::error("Erro ao gerar resposta: {$e->getMessage()}", [
                'chat_id' => $chat->uuid ?? null,
                'agent_id' => $agent->uuid ?? null,
                'trace' => $e->getTraceAsString()
            ]);
            return [
                'message' => 'Desculpe, no momento não estamos atendendo. Tente novamente em outro horário. Agradecemos a compreensão.',
                'tokens' => 0,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Transcreve áudio usando Whisper API
     */
    public function transcribeAudio(string $audioUrl, Agents $agent): ?string
    {
        $aiProvider = $this->getAIProvider($agent);
        return $aiProvider->transcribeAudio($audioUrl, $agent);
    }

    /**
     * Atualiza a última mensagem do assistente no histórico com um conteúdo sanitizado.
     */
    public function replaceLastAssistantMessage(Chats $chat, string $content): void
    {
        $this->contextService->replaceLastAssistantMessage($chat, $content);
    }

    /**
     * Analisa a qualificação do lead baseado nos critérios de closing.
     * Pode ser chamado manualmente ou é executado automaticamente durante as conversas.
     *
     * @param Chats $chat O chat a ser analisado
     * @param Agents $agent O agente (se não fornecido, busca do chat)
     * @param bool $forceAnalysis Força nova análise mesmo que já tenha sido feita
     * @return array ['qualified' => bool, 'score' => int, 'reasons' => array, 'analysis' => string]
     */
    public function analyzeLeadQualification(Chats $chat, ?Agents $agent = null, bool $forceAnalysis = false): array
    {
        if (!$agent) {
            $agent = $chat->agent;
        }

        if (!$agent) {
            return [
                'qualified' => false,
                'score' => 0,
                'reasons' => ['Agente não encontrado'],
                'analysis' => 'Não foi possível identificar o agente do chat.',
                'message' => 'Erro: agente não encontrado.'
            ];
        }

        $leadQualificationService = app(LeadQualificationService::class);
        return $leadQualificationService->analyzeLead($chat, $agent, $forceAnalysis);
    }

    /**
     * Obtém termos proibidos configurados para o agente.
     *
     * @param Agents $agent
     * @return array Lista de termos proibidos
     */
    private function getForbiddenTerms(Agents $agent): array
    {
        // Termos padrão sempre proibidos
        $defaultTerms = [
            'api_key',
            'senha',
            'password',
            'token_secreto',
            'chave_privada'
        ];

        // Termos específicos do agente (se configurado)
        $agentTerms = [];
        if (!empty($agent->forbidden_terms)) {
            if (is_array($agent->forbidden_terms)) {
                $agentTerms = $agent->forbidden_terms;
            } elseif (is_string($agent->forbidden_terms)) {
                $decoded = json_decode($agent->forbidden_terms, true);
                $agentTerms = is_array($decoded) ? $decoded : explode(',', $agent->forbidden_terms);
            }
        }

        return array_merge($defaultTerms, $agentTerms);
    }

    /**
     * Obtém contador de respostas desde última verificação de persona drift.
     *
     * @param \App\Models\MemoryStream|null $session
     * @return int Contador de respostas
     */
    private function getResponseCount($session): int
    {
        if (!$session) {
            return 0;
        }

        $conversationLog = $session->conversation_log ?? [];
        if (is_string($conversationLog)) {
            $decoded = json_decode($conversationLog, true);
            $conversationLog = json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? $decoded : [];
        }

        $history = $conversationLog['history'] ?? [];

        // Conta apenas mensagens do assistente
        $assistantCount = 0;
        foreach ($history as $entry) {
            if (($entry['role'] ?? '') === 'assistant') {
                $assistantCount++;
            }
        }

        return $assistantCount;
    }

    /**
     * Busca episódios com cache inteligente
     */
    private function getCachedOrFetchEpisodes(Chats $chat, string $userMessage): array
    {
        // Tenta buscar do cache primeiro
        $cachedEpisodes = $this->memoryCache->getCachedEpisodes($chat->uuid);

        if ($cachedEpisodes !== null) {
            Log::debug('Episódios carregados do cache', [
                'chat_id' => $chat->uuid,
                'count' => count($cachedEpisodes)
            ]);

            // Usa busca semântica aprimorada nos episódios em cache
            return $this->semanticSearch->calculateSemanticSimilarity($userMessage, $cachedEpisodes, 3);
        }

        // Busca episódios do banco e armazena em cache
        $episodes = $this->episodicMemory->findRelevantEpisodes($chat, $userMessage, 10); // Busca mais para cache

        if (!empty($episodes)) {
            $this->memoryCache->setCachedEpisodes($chat->uuid, $episodes);
        }

        // Retorna apenas os top 3 mais relevantes
        return array_slice($episodes, 0, 3);
    }

    /**
     * Verifica se é a primeira interação do chat (não há histórico de conversa ainda)
     * 
     * @param \App\Models\MemoryStream|null $session
     * @return bool True se é a primeira interação, false caso contrário
     */
    private function isFirstInteraction($session): bool
    {
        if (!$session) {
            return true; // Sem sessão = primeira interação
        }

        $log = $session->conversation_log ?? null;
        if (empty($log)) {
            return true; // Sem log = primeira interação
        }

        if (is_string($log)) {
            $decoded = json_decode($log, true);
            $log = json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? $decoded : null;
        }

        if (empty($log) || !is_array($log)) {
            return true; // Log inválido = primeira interação
        }

        $history = $log['history'] ?? [];
        if (empty($history) || !is_array($history)) {
            return true; // Histórico vazio = primeira interação
        }

        // Se há histórico, não é a primeira interação
        return false;
    }

    /**
     * Busca contexto de memória com cache
     */
    private function getCachedOrFetchMemoryContext(Chats $chat, string $userMessage, array $episodes): string
    {
        // Gera hash da query para cache
        $queryHash = $this->memoryCache->generateQueryHash($userMessage);

        // Tenta buscar do cache
        $cachedContext = $this->memoryCache->getCachedContext($chat->uuid, $queryHash);

        if ($cachedContext !== null) {
            Log::debug('Contexto carregado do cache', [
                'chat_id' => $chat->uuid,
                'query_hash' => $queryHash
            ]);
            return $cachedContext;
        }

        // Busca contexto normal e armazena em cache
        $context = $this->memoryFetching->fetchRelevantMemory($chat, $userMessage);

        $this->memoryCache->setCachedContext($chat->uuid, $queryHash, $context);

        return $context;
    }

    /**
     * Decide se deve executar operações pesadas baseado na configuração do agente
     */
    private function shouldPerformHeavyOperations(Agents $agent): bool
    {
        // Verifica se operações pesadas estão habilitadas globalmente
        if (!config('knowledge.heavy_operations.enabled', true)) {
            return false;
        }

        // Se requer plano pago, verifica créditos do usuário
        if (config('knowledge.heavy_operations.require_paid_plan', true)) {
            $userPlan = $agent->user?->plan;
            $minCredits = config('knowledge.heavy_operations.min_credits_required', 1000);

            if (!$userPlan || $userPlan->credits < $minCredits) {
                return false;
            }
        }

        // Aplica probabilidade de execução para balancear carga
        $probability = config('knowledge.heavy_operations.execution_probability', 0.7);
        return rand(1, 100) <= ($probability * 100);
    }

    /**
     * Reflexão básica baseada em heurísticas (muito mais rápida que AI)
     */
    private function basicReflection(string $userMessage, string $assistantResponse): array
    {
        // Lógica heurística simples para determinar se deve armazenar
        $shouldStore = false;
        $importance = 0;

        // Verifica se a mensagem do usuário indica algo importante
        $importantKeywords = [
            'lembre',
            'importante',
            'sempre',
            'nunca esqueça',
            'minha preferência',
            'gosto de',
            'não gosto',
            'meu objetivo',
            'minha meta',
            'planejo'
        ];

        $messageLower = strtolower($userMessage . ' ' . $assistantResponse);
        foreach ($importantKeywords as $keyword) {
            if (str_contains($messageLower, $keyword)) {
                $shouldStore = true;
                $importance += 0.3;
                break;
            }
        }

        // Verifica se há informações pessoais
        $personalKeywords = [
            'meu nome',
            'minha idade',
            'moro em',
            'trabalho como',
            'minha empresa',
            'meu cargo',
            'minha família'
        ];

        foreach ($personalKeywords as $keyword) {
            if (str_contains($messageLower, $keyword)) {
                $shouldStore = true;
                $importance += 0.4;
                break;
            }
        }

        return [
            'store' => $shouldStore,
            'memory' => $shouldStore ? [
                'summary' => substr($userMessage, 0, 100) . '...',
                'intent' => 'general',
                'importance' => min(1.0, $importance)
            ] : null,
            'should_improve' => false
        ];
    }

    /**
     * ✅ Valida se o provedor suporta os tipos de mídia dos arquivos multimodais
     *
     * @param \App\Http\Controllers\Knowledge\Services\AIProviders\AIProviderInterface $provider
     * @param array $files Array de arquivos multimodais
     * @param Agents $agent
     * @return array ['valid' => bool, 'unsupported_types' => array, 'error_message' => string]
     */
    private function validateProviderMultimodalCapabilities($provider, array $files, Agents $agent): array
    {
        $unsupportedTypes = [];
        $fileTypesFound = [];

        // Identificar tipos de mídia nos arquivos
        foreach ($files as $file) {
            if (!is_array($file)) {
                continue;
            }

            $fileType = $file['type'] ?? null;

            // Se tipo não especificado, tentar detectar por mime_type ou extension
            if (empty($fileType)) {
                $mimeType = $file['mime_type'] ?? $file['mimetype'] ?? null;
                $extension = $file['extension'] ?? null;

                if ($mimeType) {
                    if (str_starts_with($mimeType, 'image/')) {
                        $fileType = 'image';
                    } elseif (str_starts_with($mimeType, 'audio/')) {
                        $fileType = 'audio';
                    } elseif (str_starts_with($mimeType, 'video/')) {
                        $fileType = 'video';
                    } elseif (in_array($mimeType, ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'])) {
                        $fileType = 'document';
                    }
                } elseif ($extension) {
                    $imageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];
                    $audioExts = ['mp3', 'wav', 'ogg', 'm4a', 'aac', 'flac'];
                    $docExts = ['pdf', 'doc', 'docx', 'txt', 'rtf'];

                    if (in_array(strtolower($extension), $imageExts)) {
                        $fileType = 'image';
                    } elseif (in_array(strtolower($extension), $audioExts)) {
                        $fileType = 'audio';
                    } elseif (in_array(strtolower($extension), $docExts)) {
                        $fileType = 'document';
                    }
                }
            }

            if ($fileType) {
                $fileTypesFound[$fileType] = true;
            }
        }

        // Verificar se o provedor suporta cada tipo encontrado
        if (isset($fileTypesFound['image']) && !$provider->supportsImages()) {
            $unsupportedTypes[] = 'imagem';
        }

        if (isset($fileTypesFound['audio']) && !$provider->supportsAudio()) {
            $unsupportedTypes[] = 'áudio';
        }

        if (isset($fileTypesFound['document']) && !$provider->supportsDocuments()) {
            $unsupportedTypes[] = 'documento';
        }

        if (isset($fileTypesFound['video'])) {
            // Video geralmente é tratado como image (vision) ou não suportado
            // Por padrão, muitos provedores não suportam video diretamente
            if (!$provider->supportsImages()) {
                $unsupportedTypes[] = 'vídeo';
            }
        }

        if (!empty($unsupportedTypes)) {
            $providerName = $this->getProviderDisplayName($provider);
            $typesList = implode(', ', $unsupportedTypes);

            return [
                'valid' => false,
                'unsupported_types' => $unsupportedTypes,
                'error_message' => "Desculpe, o provedor de IA configurado ({$providerName}) não suporta processamento de {$typesList}. Por favor, use um provedor compatível ou envie apenas mensagens de texto."
            ];
        }

        return [
            'valid' => true,
            'unsupported_types' => [],
            'error_message' => ''
        ];
    }

    /**
     * Obtém nome amigável do provedor para mensagens de erro
     *
     * @param \App\Http\Controllers\Knowledge\Services\AIProviders\AIProviderInterface $provider
     * @return string
     */
    private function getProviderDisplayName($provider): string
    {
        $capabilities = $provider->getCapabilities();
        $providerSlug = $capabilities['provider'] ?? 'unknown';

        $displayNames = [
            'openai' => 'OpenAI',
            'claude' => 'Anthropic Claude',
            'gemini' => 'Google Gemini',
            'groq' => 'Groq',
            'xai' => 'xAI',
            'meta' => 'Meta Llama',
            'mistral' => 'Mistral',
            'cohere' => 'Cohere',
            'deepseek' => 'DeepSeek',
            'perplexity' => 'Perplexity',
            'together' => 'Together AI',
            'fireworks' => 'Fireworks AI'
        ];

        return $displayNames[$providerSlug] ?? ucfirst($providerSlug);
    }

    /**
     * Processa múltiplos arquivos multimodais
     *
     * Aceita arquivos com ou sem tipo especificado - detecta automaticamente se necessário
     */
    private function processMultimodalFiles(Chats $chat, Agents $agent, array $files): array
    {
        $results = [];

        foreach ($files as $file) {
            // Validação básica: precisa ter pelo menos o path
            if (!is_array($file) || empty($file['path'])) {
                Log::warning('Arquivo multimodal mal formatado - falta path', ['file' => $file]);
                continue;
            }

            // Normaliza estrutura do arquivo (garante que tem path)
            $normalizedFile = [
                'path' => $file['path'],
                'type' => $file['type'] ?? null, // Opcional - será detectado automaticamente
                'mime_type' => $file['mime_type'] ?? $file['mimetype'] ?? null,
                'extension' => $file['extension'] ?? null,
                'metadata' => $file['metadata'] ?? []
            ];

            // Usa processamento assíncrono para arquivos grandes
            $result = $this->asyncMultimodalProcessor->processAsyncIfNeeded($normalizedFile, $chat, $agent);

            // 🆕 Preservar caminho original do arquivo no metadata para uso com vision
            if (!isset($result['metadata']['file_path']) && !empty($file['path'])) {
                $result['metadata']['file_path'] = $file['path'];
            }

            $results[] = $result;

            Log::info('Arquivo multimodal processado', [
                'chat_id' => $chat->uuid,
                'file_path' => $normalizedFile['path'],
                'file_type' => $result['metadata']['type'] ?? 'unknown',
                'detected_automatically' => empty($file['type']),
                'success' => $result['success'],
                'async' => $result['async'] ?? false
            ]);
        }

        return $results;
    }

    /**
     * Constrói contexto multimodal para incluir no system prompt
     */
    private function buildMultimodalContext(array $multimodalResults, string $userMessage): string
    {
        $context = "=== CONTEÚDO MULTIMODAL PROCESSADO ===\n";

        foreach ($multimodalResults as $result) {
            if (!$result['success'] || empty($result['content'])) {
                continue;
            }

            $content = $result['content'];
            $metadata = $result['metadata'] ?? [];

            // Adicionar seção específica por tipo
            switch ($metadata['type'] ?? 'unknown') {
                case 'audio':
                    $context .= "\n--- TRANSCRIÇÃO DE ÁUDIO ---\n";
                    if (isset($content['transcription'])) {
                        $context .= "Transcrição: " . $this->truncateText($content['transcription'], 2000) . "\n";
                    }
                    if (isset($content['emotional_context'])) {
                        $context .= "Contexto Emocional: " . $content['emotional_context'] . "\n";
                    }
                    break;

                case 'document':
                    $context .= "\n--- CONTEÚDO DE DOCUMENTO ---\n";
                    if (isset($content['agent_context'])) {
                        $context .= $this->truncateText($content['agent_context'], 3000) . "\n";
                    }
                    break;

                case 'image':
                    $context .= "\n--- ANÁLISE DE IMAGEM ---\n";
                    if (isset($content['analysis']) && !empty($content['analysis'])) {
                        // Análise completa com IA disponível
                        $context .= "Análise Visual (IA): " . $this->truncateText($content['analysis'], 2000) . "\n";
                    } elseif (isset($content['description']) && !empty($content['description'])) {
                        // Descrição básica ou fallback
                        $context .= "Descrição: " . $this->truncateText($content['description'], 1000) . "\n";
                    }
                    if (isset($content['width']) && isset($content['height'])) {
                        $context .= "Dimensões: {$content['width']}x{$content['height']} pixels\n";
                    }
                    if (isset($content['model_used'])) {
                        $context .= "Modelo de análise: {$content['model_used']}\n";
                    }
                    break;
            }
        }

        $context .= "\n=== FIM DO CONTEÚDO MULTIMODAL ===\n";

        return $context;
    }

    /**
     * Trunca texto para não sobrecarregar o contexto
     */
    private function truncateText(string $text, int $maxLength = 1000): string
    {
        if (strlen($text) <= $maxLength) {
            return $text;
        }

        return substr($text, 0, $maxLength - 3) . '...';
    }

    /**
     * Deduz créditos do usuário baseado nos tokens utilizados
     *
     * @param $user User object
     * @param int $tokensUsed Tokens consumidos pela resposta
     * @return bool True se a dedução foi bem-sucedida, false caso contrário
     */
    private function deductUserCredits($user, int $tokensUsed): bool
    {
        if (!$user) {
            Log::error('Tentativa de debitar créditos de usuário inexistente', [
                'tokens_used' => $tokensUsed
            ]);
            return false;
        }

        // Calcular créditos a serem debitados (tokensUsed / 1000)
        $creditsToDeduct = $tokensUsed / 1000;

        try {
            // Importar modelo User
            $userModel = app(\App\Models\User::class);

            // Usar transação com lock pessimista para máxima segurança
            return DB::transaction(function () use ($user, $tokensUsed, $creditsToDeduct, $userModel) {
                // Bloquear o registro do usuário para evitar condições de corrida
                $userWithLock = $userModel::where('uuid', $user->uuid)
                    ->lockForUpdate()
                    ->first();

                if (!$userWithLock) {
                    Log::error('Usuário não encontrado durante debitação de créditos', [
                        'user_uuid' => $user->uuid,
                        'tokens_used' => $tokensUsed
                    ]);
                    return false;
                }

                // Calcular saldo total disponível
                $totalCredits = ($userWithLock->credits ?? 0) + ($userWithLock->combo ?? 0);

                // Verificar se usuário tem saldo suficiente
                if ($totalCredits < $creditsToDeduct) {
                    Log::error('Usuário não tem créditos suficientes para a operação (com lock)', [
                        'user_id' => $userWithLock->uuid,
                        'total_credits' => $totalCredits,
                        'credits_needed' => $creditsToDeduct,
                        'tokens_used' => $tokensUsed
                    ]);
                    return false;
                }

                // Debitar do combo primeiro
                $comboUsed = 0;
                $creditsUsed = 0;

                if ($userWithLock->combo >= $creditsToDeduct) {
                    // Combo tem créditos suficientes
                    $userWithLock->decrement('combo', $creditsToDeduct);
                    $comboUsed = $creditsToDeduct;
                } else {
                    // Usar todo o combo disponível
                    $comboUsed = $userWithLock->combo;
                    $userWithLock->update(['combo' => 0]);

                    // Completar com credits
                    $remainingCredits = $creditsToDeduct - $comboUsed;
                    if ($remainingCredits > 0) {
                        $userWithLock->decrement('credits', $remainingCredits);
                        $creditsUsed = $remainingCredits;
                    }
                }

                Log::info('Créditos debitados com sucesso (com lock transacional)', [
                    'user_id' => $userWithLock->uuid,
                    'tokens_used' => $tokensUsed,
                    'credits_deducted' => $creditsToDeduct,
                    'combo_used' => $comboUsed,
                    'credits_used' => $creditsUsed,
                    'remaining_combo' => $userWithLock->combo,
                    'remaining_credits' => $userWithLock->credits
                ]);

                return true;
            });
        } catch (\Exception $e) {
            Log::error('Erro ao debitar créditos do usuário (com lock)', [
                'user_id' => $user->uuid,
                'tokens_used' => $tokensUsed,
                'credits_to_deduct' => $creditsToDeduct,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }
}
