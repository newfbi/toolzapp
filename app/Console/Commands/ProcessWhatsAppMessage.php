<?php

namespace App\Console\Commands;

use App\Helpers\PhoneHelper;
use App\Http\Controllers\Knowledge\Context\ContextController;
use App\Models\Agents;
use App\Models\Chats;
use App\Models\Messages;
use App\Models\UserList;
use App\Models\WhatsappDispatchItem;
use App\Models\WhatsappInstance;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ProcessWhatsAppMessage extends Command
{
    protected $signature = 'whatsapp:process {payload?}';
    protected $description = 'Processa mensagens do WhatsApp recebidas via WebSocket';

    public function handle()
    {
        $payloadArg = $this->argument('payload');

        // Se for um caminho para arquivo JSON (usado pelo Listener)
        if ($payloadArg && file_exists($payloadArg)) {
            $json = file_get_contents($payloadArg);
            unlink($payloadArg); // limpa arquivo temporário
            Log::info('Arquivo temporário removido', ['file' => $payloadArg]);
        } else {
            // Caso contrário, trata como JSON bruto passado diretamente
            $json = $this->fixJsonFormat($payloadArg);
        }

        $decodedPayload = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE || !$decodedPayload) {
            $errorMessage = json_last_error_msg();
            echo json_encode(['status' => 'error', 'message' => "Erro ao decodificar JSON: $errorMessage"]);
            return;
        }

        $whatsappMessageId = $decodedPayload['whatsapp_message_id'] ?? null;
        if ($whatsappMessageId) {
            try {
                $alreadyStored = Messages::where('agent_id', $decodedPayload['agent_id'] ?? '')
                    ->where('whatsapp_message_id', $whatsappMessageId)
                    ->exists();

                if ($alreadyStored) {
                    echo json_encode(['status' => 'duplicate', 'message' => null]);
                    return;
                }
            } catch (\Throwable $e) {
                // A migração mantém esta coluna disponível; se uma instalação
                // antiga ainda não a aplicou, o lock/cache abaixo continua como
                // proteção temporária sem derrubar o recebimento.
                Log::warning('Não foi possível consultar idempotência WhatsApp', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $dedupeSource = $decodedPayload['whatsapp_message_id']
            ?? implode('|', [
                $decodedPayload['agent_id'] ?? '',
                $decodedPayload['from'] ?? '',
                $decodedPayload['timestamp'] ?? '',
                $decodedPayload['body'] ?? '',
            ]);
        $dedupeKey = 'whatsapp:incoming:' . hash('sha256', (string) $dedupeSource);
        $lock = Cache::lock($dedupeKey, 900);

        if (!$lock->get()) {
            echo json_encode(['status' => 'duplicate', 'message' => null]);
            return;
        }

        try {
            $response = $this->processWhatsAppMessage($decodedPayload);

            if (in_array($response['status'], ['success', 'duplicate'], true)) {
                echo json_encode($response);
            } else {
                echo json_encode([
                    'status' => 'error',
                    'message' => $response['message'] ?? "Erro desconhecido ao processar a mensagem"
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Erro no processamento de mensagem WhatsApp', [
                'error' => $e->getMessage(),
                'payload' => $decodedPayload,
                'trace' => $e->getTraceAsString()
            ]);

            echo json_encode([
                'status' => 'error',
                'message' => "Erro ao processar a mensagem: " . $e->getMessage()
            ]);
        } finally {
            optional($lock)->release();
        }
    }

    private function fixJsonFormat($payload)
    {
        if (!$payload) return '{}';

        $payload = trim($payload);
        $payload = preg_replace('/([{,])\s*([a-zA-Z0-9_]+)\s*:/', '$1"$2":', $payload);
        $payload = preg_replace('/:\s*"?(.*?)@c\.us"?/', ': "$1"', $payload);
        $payload = preg_replace_callback('/:\s*([^",\s][^,}]*)/', function ($matches) {
            $value = trim($matches[1]);
            if (preg_match('/^".*"$/', $value) || in_array($value[0], ['{', '['])) {
                return ": $value";
            }
            return ': "' . addslashes($value) . '"';
        }, $payload);

        return $payload;
    }

    /**
     * Processa mensagem recebida do WhatsApp
     */
    private function processWhatsAppMessage(array $payload): array
    {
        // 🆕 Extrair dados organizados do payload
        $from = $payload['from'] ?? ''; // Quem enviou (número real)
        $to = $payload['to'] ?? ''; // Quem recebeu (número real)
        $isFromMe = $payload['isFromMe'] ?? false; // Se a mensagem é do próprio agente
        $contactLid = $payload['contact_lid'] ?? null; // @lid do contato (se disponível)
        $body = $payload['body'] ?? '';
        $agentId = $payload['agent_id'] ?? ''; // ID do agente
        $messageType = $payload['messageType'] ?? 'text';
        $direction = $payload['direction'] ?? 'input';
        $userName = $payload['userName'] ?? null; // 🆕 Nome do usuário (pushName quando fromMe: false)
        $mediaPath = $payload['mediaPath'] ?? null; // 🆕 Caminho do arquivo de mídia baixado
        $mimetype = $payload['mimetype'] ?? null; // 🆕 Tipo MIME da mídia
        $filename = $payload['filename'] ?? null; // 🆕 Nome do arquivo (para documentos)
        $caption = $payload['caption'] ?? null; // 🆕 Legenda (para imagens/vídeos)

        // Validar dados obrigatórios
        // Para mensagens de mídia (áudio, imagem, documento), body pode estar vazio
        $isMediaMessage = in_array($messageType, ['audio', 'image', 'document', 'video']);
        $requiresBody = !$isMediaMessage;

        if (empty($from) || empty($to) || empty($agentId) || ($requiresBody && empty($body))) {
            // Para mídia, também verificar se tem mediaPath
            if ($isMediaMessage && empty($mediaPath)) {
                Log::warning('Payload inválido - mídia sem mediaPath', [
                    'from' => $from,
                    'to' => $to,
                    'agentId' => $agentId,
                    'messageType' => $messageType
                ]);
                return [
                    'status' => 'error',
                    'message' => 'Payload inválido: mensagem de mídia requer mediaPath'
                ];
            }

            Log::warning('Payload inválido - dados obrigatórios faltando', [
                'from' => $from,
                'to' => $to,
                'body' => $body,
                'agentId' => $agentId,
                'direction' => $direction,
                'messageType' => $messageType
            ]);
            return [
                'status' => 'error',
                'message' => 'Payload inválido: from, to, agent_id são obrigatórios' . ($requiresBody ? ' e body é obrigatório para mensagens de texto' : '')
            ];
        }

        // 🆕 Determinar números corretos baseado na direção
        $senderPhone = $from; // Quem enviou (sempre número real)
        $receiverPhone = $to; // Quem recebeu (sempre número real)

        Log::info('Processando mensagem WhatsApp', [
            'direction' => $direction,
            'from' => $senderPhone,
            'to' => $receiverPhone,
            'contact_lid' => $contactLid,
            'agent_id' => $agentId,
            'user_name' => $userName // 🆕 Nome do usuário (pushName quando fromMe: false)
        ]);

        try {
            // 1. Buscar instância WhatsApp e agente
            // 🆕 REMOVIDO: where('status', 'connected') - permitir processar mensagens independente do status
            // O Baileys pode estar conectado mesmo se o status no banco estiver desatualizado
            $instance = WhatsappInstance::where('agent_id', $agentId)
                ->with('agent')
                ->first();

            if (!$instance || !$instance->agent) {
                Log::warning('Instância ou agente não encontrados', [
                    'agent_id' => $agentId,
                    'instance' => $instance,
                    'agent' => $instance?->agent
                ]);
                return [
                    'status' => 'error',
                    'message' => 'Instância WhatsApp não encontrada ou agente não disponível'
                ];
            }

            $agent = $instance->agent;
            $userId = $instance->user_id;

            // Eventos originados pela própria conta nunca devem entrar no fluxo
            // de automação. Além de evitar loops, isso impede salvar e cobrar uma
            // mensagem que não veio do contato.
            if ($isFromMe) {
                Log::info('Mensagem originada pela própria conta ignorada', [
                    'agent_id' => $agent->uuid,
                    'from' => $senderPhone,
                    'to' => $receiverPhone,
                    'whatsapp_message_id' => $payload['whatsapp_message_id'] ?? null,
                ]);

                return [
                    'status' => 'success',
                    'message' => null,
                    'credit_usage' => 0,
                ];
            }

            if ($agent->phone == null) {
                $agent->phone = $receiverPhone;
                $agent->save();
            }

            // 2. Buscar ou criar chat
            // 🆕 Para mensagens recebidas: usar senderPhone como identificador do contato
            // 🆕 Para mensagens enviadas: usar receiverPhone como identificador do contato
            $contactPhone = ($direction === 'input') ? $senderPhone : $receiverPhone;
            $chat = $this->findOrCreateChat($contactPhone, $userId, $agent, $contactLid, $userName);

            // 3. Filtrar mensagens do bot para evitar loops
            if (str_contains($body, "\u{200B}")) { // Caractere invisível usado pelo bot
                Log::info('Mensagem do bot detectada, pulando processamento para evitar loop', [
                    'direction' => $direction,
                    'from' => $senderPhone,
                    'to' => $receiverPhone
                ]);
                return [
                    'status' => 'success',
                    'message' => 'Mensagem do bot ignorada para evitar loop',
                    'credit_usage' => 0
                ];
            }

            // 🆕 Mensagem recebida do contato
            // Para mídia (áudio, imagem, documento), salvar com body vazio ou caption (processamento será feito depois)
            $messageContentToSave = $isMediaMessage ? ($caption ?: ($filename ?: '')) : $body;

            // Salvar mensagem do usuário (será atualizada com prompt_tokens após generateResponse)
            $messageStored = $this->saveIncomingMessage($chat, $agent, $messageContentToSave, $senderPhone, $messageType, [
                'mediaPath' => $mediaPath,
                'mimetype' => $mimetype,
                'filename' => $filename,
                'caption' => $caption,
                'whatsappMessageId' => $payload['whatsapp_message_id'] ?? null,
            ]);

            if (!$messageStored) {
                return [
                    'status' => 'duplicate',
                    'message' => null,
                    'credit_usage' => 0,
                ];
            }

            // 🆕 Recarregar chat do banco para garantir dados atualizados
            $chat->refresh();

            // Opt-out deve ter precedência sobre qualquer automação. Não gerar
            // uma nova resposta depois de o contato pedir interrupção.
            if (!$isFromMe) {
                $optOutText = trim((string) ($body ?: $caption));
                $optOutText = mb_strtolower((string) preg_replace('/[^\pL\pN]+/u', ' ', $optOutText));
                $optOutKeywords = ['stop', 'sair', 'parar', 'cancelar', 'remover', 'unsubscribe'];

                if (in_array($optOutText, $optOutKeywords, true)) {
                    $chat->update(['is_pause' => 'true']);
                    $optedOutContacts = $this->markMarketingOptOut($userId, $senderPhone);
                    Log::notice('Opt-out de WhatsApp recebido; automação pausada', [
                        'chat_id' => $chat->uuid,
                        'agent_id' => $agentId,
                        'phone' => $senderPhone,
                        'marketing_contacts_updated' => $optedOutContacts,
                    ]);

                    return [
                        'status' => 'success',
                        'message' => null,
                        'credit_usage' => 0,
                    ];
                }
            }

            // 🆕 Verificar is_pause comparando explicitamente com 'true' (enum)
            if ($chat->is_pause === 'true') {
                Log::info('Chat em pausa, ignorando processamento', [
                    'chat_id' => $chat->uuid,
                    'sender_phone' => $senderPhone,
                    'receiver_phone' => $receiverPhone,
                    'is_pause' => $chat->is_pause
                ]);
                return [
                    'status' => 'success',
                    'message' => 'Chat em pausa, ignorando processamento'
                ];
            }

            // Defesa adicional: se o histórico do disparo não pôde ser
            // gravado ou houve uma corrida entre envio e resposta, qualquer
            // destinatário que recebeu marketing deve continuar sem resposta
            // automática até que um operador libere o chat manualmente.
            if (!$isFromMe && $this->wasMarketingRecipient($agentId, $senderPhone)) {
                $chat->update(['is_pause' => 'true']);
                Log::notice('Destinatário de marketing mantido pausado; resposta automática bloqueada', [
                    'chat_id' => $chat->uuid,
                    'agent_id' => $agentId,
                    'phone' => $senderPhone,
                ]);

                return [
                    'status' => 'success',
                    'message' => null,
                    'credit_usage' => 0,
                ];
            }

            // 🆕 4.5. Processar mídia (áudio, imagem, documento)
            $messageToProcess = $body ?: ($caption ?: ($filename ?: ''));
            $multimodalFiles = [];

            // Processar áudio (transcrição)
            if ($messageType === 'audio' && !empty($mediaPath)) {
                // Verificar se agente está habilitado para áudio
                $audioEnabled = filter_var($agent->audio, FILTER_VALIDATE_BOOLEAN) ||
                    (is_string($agent->audio) && in_array(strtolower($agent->audio), ['true', '1', 'enabled']));

                if (!$audioEnabled) {
                    Log::info('Agente não habilitado para áudio', [
                        'agent_id' => $agent->uuid,
                        'agent_audio' => $agent->audio
                    ]);
                    return [
                        'status' => 'success',
                        'message' => 'Desculpe, mas não consigo processar áudios. Você poderia digitar sua mensagem? Estou aqui para ajudar!',
                        'credit_usage' => 0
                    ];
                }

                // Transcrever áudio
                $contextController = app(ContextController::class);
                Log::info('Transcrevendo áudio recebido', [
                    'mediaPath' => $mediaPath,
                    'agent_id' => $agent->uuid
                ]);

                $transcription = $contextController->transcribeAudio($mediaPath, $agent);

                if (empty($transcription)) {
                    Log::warning('Falha na transcrição de áudio', [
                        'mediaPath' => $mediaPath,
                        'agent_id' => $agent->uuid
                    ]);
                    return [
                        'status' => 'success',
                        'message' => 'Não consegui entender o áudio. Você poderia digitar sua mensagem?',
                        'credit_usage' => 0
                    ];
                }

                $messageToProcess = $transcription;

                // Atualizar a mensagem salva com a transcrição
                $lastMessage = Messages::where('chat_id', $chat->uuid)
                    ->where('phone', $senderPhone)
                    ->where('direction', 'put')
                    ->orderByDesc('created_at')
                    ->first();

                if ($lastMessage) {
                    $lastMessage->update([
                        'content' => "Áudio: {$transcription}"
                    ]);
                    Log::info('Mensagem atualizada com transcrição de áudio', [
                        'message_id' => $lastMessage->uuid
                    ]);
                }

                Log::info('Áudio transcrito com sucesso', [
                    'mediaPath' => $mediaPath,
                    'transcription_length' => strlen($transcription),
                    'agent_id' => $agent->uuid
                ]);

                // 🆕 Deletar arquivo de áudio após processamento bem-sucedido
                $audioAbsolutePath = storage_path('app/' . $mediaPath);
                if (file_exists($audioAbsolutePath)) {
                    try {
                        unlink($audioAbsolutePath);
                        Log::info('Arquivo de áudio deletado após processamento', [
                            'mediaPath' => $mediaPath,
                            'agent_id' => $agent->uuid
                        ]);
                    } catch (\Exception $deleteError) {
                        Log::warning('Erro ao deletar arquivo de áudio (não crítico)', [
                            'mediaPath' => $mediaPath,
                            'error' => $deleteError->getMessage(),
                            'agent_id' => $agent->uuid
                        ]);
                    }
                }
            }

            // 🆕 Preparar arquivos multimodais para imagens e documentos
            if (in_array($messageType, ['image', 'document']) && !empty($mediaPath)) {
                // Construir caminho absoluto do arquivo
                $absolutePath = storage_path('app/' . $mediaPath);

                // Verificar se arquivo existe
                if (file_exists($absolutePath)) {
                    $multimodalFiles[] = [
                        'path' => $absolutePath,
                        'type' => $messageType,
                        'mime_type' => $mimetype,
                        'metadata' => [
                            'filename' => $filename,
                            'caption' => $caption,
                            'original_message_type' => $messageType
                        ]
                    ];

                    Log::info('Arquivo multimodal preparado', [
                        'message_type' => $messageType,
                        'mediaPath' => $mediaPath,
                        'absolute_path' => $absolutePath,
                        'agent_id' => $agent->uuid
                    ]);
                } else {
                    Log::warning('Arquivo multimodal não encontrado', [
                        'message_type' => $messageType,
                        'mediaPath' => $mediaPath,
                        'absolute_path' => $absolutePath,
                        'agent_id' => $agent->uuid
                    ]);
                }
            }

            // 5. Processar mensagem com ContextController (fluxo natural)
            // A validação de agentes pessoais agora é feita no JavaScript (server.js)
            // Aqui apenas processamos mensagens que chegam normalmente
            Log::info('Processando mensagem com ContextController', [
                'direction' => $direction,
                'agent_personal' => $agent->is_personal,
                'instance_phone' => $instance->phone_number,
                'sender_phone' => $senderPhone,
                'receiver_phone' => $receiverPhone,
                'message_type' => $messageType,
                'is_media' => $isMediaMessage,
                'has_multimodal_files' => !empty($multimodalFiles)
            ]);

            $contextController = app(ContextController::class);
            $options = [];

            // Passar arquivos multimodais se houver
            if (!empty($multimodalFiles)) {
                $options['multimodal_files'] = $multimodalFiles;
            }

            $response = $contextController->generateResponse($chat, $agent, $messageToProcess, $options);

            // 🆕 Deletar arquivos multimodais (imagens/documentos) após processamento bem-sucedido
            if (!empty($multimodalFiles)) {
                foreach ($multimodalFiles as $file) {
                    $filePath = $file['path'] ?? null;
                    if ($filePath && file_exists($filePath)) {
                        try {
                            unlink($filePath);
                            Log::info('Arquivo multimodal deletado após processamento', [
                                'file_path' => $filePath,
                                'file_type' => $file['type'] ?? 'unknown',
                                'agent_id' => $agent->uuid
                            ]);
                        } catch (\Exception $deleteError) {
                            Log::warning('Erro ao deletar arquivo multimodal (não crítico)', [
                                'file_path' => $filePath,
                                'error' => $deleteError->getMessage(),
                                'agent_id' => $agent->uuid
                            ]);
                        }
                    }
                }
            }

            // 6. Salvar resposta do assistente
            if (isset($response['message'])) {
                // Sempre salvar resposta quando há uma, independente da direção
                // O ContextController já trata da lógica de créditos e processamento
                $completionTokens = $response['completion_tokens'] ?? 0;
                $this->saveOutgoingMessage($chat, $agent, $response['message'], $receiverPhone, $completionTokens);
            } else {
                Log::warning('Nenhuma resposta gerada pelo ContextController', [
                    'direction' => $direction,
                    'agent_personal' => $agent->is_personal
                ]);
                $response = ['message' => null, 'tokens' => 0, 'prompt_tokens' => 0, 'completion_tokens' => 0];
            }

            // ✅ Atualizar mensagem do usuário com prompt_tokens
            if (isset($response['prompt_tokens']) && $response['prompt_tokens'] > 0) {
                $lastUserMessage = Messages::where('chat_id', $chat->uuid)
                    ->where('phone', $senderPhone)
                    ->where('direction', 'put')
                    ->orderByDesc('created_at')
                    ->first();

                if ($lastUserMessage) {
                    $lastUserMessage->update(['credit_usage' => $response['prompt_tokens']]);
                    Log::info('Mensagem do usuário atualizada com prompt_tokens', [
                        'message_id' => $lastUserMessage->uuid,
                        'prompt_tokens' => $response['prompt_tokens']
                    ]);
                }
            }

            // 🆕 DETECÇÃO CORRETA DE SELF-MESSAGE
            // Self-message: agente pessoal + output + receiver é o próprio assistant_id
            $isSelfMessage = $isFromMe;

            // Preparar resposta baseada no processamento
            if (isset($response['message'])) {
                // Mensagem foi processada e tem resposta
                return [
                    'status' => 'success',
                    'message' => $response['message'],
                    'credit_usage' => $response['tokens'] ?? 0,
                    'payload' => $payload
                ];
            } else {
                // Mensagem processada mas sem resposta do agente
                Log::info('Mensagem processada - sem resposta gerada', [
                    'has_response' => false,
                    'agent_personal' => $agent->is_personal,
                    'is_self_message' => $isSelfMessage
                ]);

                return [
                    'status' => 'success',
                    'message' => null, // Não enviar resposta
                    'credit_usage' => 0,
                    'payload' => $payload
                ];
            }
        } catch (\Exception $e) {
            Log::error('Erro no processamento da mensagem WhatsApp', [
                'error' => $e->getMessage(),
                'payload' => $payload,
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'status' => 'error',
                'message' => 'Erro interno ao processar mensagem: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Busca ou cria chat baseado no telefone do contato
     */
    private function findOrCreateChat(string $contactPhone, string $userId, Agents $agent, $contactLid, string $userName): Chats
    {
        $canonicalDigits = preg_replace('/[^\d]/', '', $contactPhone);
        $localDigits = (strlen($canonicalDigits) >= 12 && str_starts_with($canonicalDigits, '55'))
            ? substr($canonicalDigits, 2)
            : $canonicalDigits;

        // 1. Tentar encontrar por contact_lid ou por telefone em múltiplos formatos (incluindo chats criados por disparo)
        $chat = Chats::where('agent_id', $agent->uuid)
            ->where(function ($query) use ($contactLid, $contactPhone, $canonicalDigits, $localDigits) {
                if (!empty($contactLid)) {
                    $query->where('contact_lid', $contactLid);
                }

                $query->orWhere('phone', $contactPhone);

                if (!empty($canonicalDigits)) {
                    $query->orWhere('phone', 'like', "%{$canonicalDigits}%");
                }

                if (!empty($localDigits) && strlen($localDigits) >= 8) {
                    $query->orWhere('phone', 'like', "%{$localDigits}%");
                }
            })
            ->first();

        // Buscar nome do lead na tabela user_lists se não tiver nome ou for genérico
        $leadName = $userName;
        $leadContact = UserList::where('user_id', $userId)
            ->where(function ($q) use ($canonicalDigits, $localDigits) {
                if (!empty($localDigits)) {
                    $q->where('phone', 'like', "%{$localDigits}%");
                }
                if (!empty($canonicalDigits)) {
                    $q->orWhere('phone', 'like', "%{$canonicalDigits}%");
                }
            })->first();

        if ($leadContact && !empty($leadContact->name)) {
            $leadName = $leadContact->name;
        }

        $chatName = $leadName ?: "WhatsApp: {$contactPhone}";

        if (!$chat) {
            // Criar novo chat
            $chatId = Str::limit((string) Str::uuid(), 8, '');

            $chat = Chats::create([
                'uuid' => $chatId,
                'user_id' => $userId,
                'agent_id' => $agent->uuid,
                'contact_lid' => $contactLid,
                'phone' => PhoneHelper::toWhatsAppJid($contactPhone),
                'name' => $chatName,
                'lead_interval' => 6,
                'expertise' => 'assistente',
                'status' => 'active',
                'is_pause' => $leadContact ? 'true' : 'false',
                'time' => now()->format('H:i'),
            ]);

            Log::info('Novo chat criado via WhatsApp recebido', [
                'chat_id' => $chatId,
                'contact_phone' => $contactPhone,
                'contact_lid' => $contactLid,
                'user_name' => $chatName,
                'agent_id' => $agent->uuid
            ]);
        } else {
            // Atualizar chat existente se necessário
            $updateData = [];

            if ($contactLid && empty($chat->contact_lid)) {
                $updateData['contact_lid'] = $contactLid;
            }

            if ($leadName && (empty($chat->name) || str_starts_with($chat->name, 'WhatsApp:') || str_starts_with($chat->name, 'Lead:'))) {
                $updateData['name'] = $leadName;
            }

            if (!empty($updateData)) {
                $chat->update($updateData);
            }
        }

        return $chat;
    }

    /**
     * Verifica se o contato já recebeu ao menos um item de marketing enviado
     * por este agente. A consulta considera apenas itens efetivamente enviados.
     */
    private function wasMarketingRecipient(string $agentId, string $phone): bool
    {
        $digits = preg_replace('/[^0-9]/', '', $phone);
        if (!$digits) {
            return false;
        }

        $suffix = substr($digits, -11);

        return WhatsappDispatchItem::where('status', 'sent')
            ->where(function ($query) use ($digits, $suffix) {
                $query->where('phone', 'like', "%{$digits}%")
                    ->orWhere('phone', 'like', "%{$suffix}%");
            })
            ->whereHas('dispatch', fn ($query) => $query->where('agent_id', $agentId))
            ->exists();
    }

    /**
     * Separa a pausa da automação do Agente do consentimento de marketing.
     * Um opt-out explícito invalida o contato na lista, enquanto is_pause
     * continua sendo a proteção contra respostas automáticas.
     */
    private function markMarketingOptOut(string $userId, string $phone): int
    {
        $digits = preg_replace('/[^0-9]/', '', $phone);
        if (!$digits) {
            return 0;
        }

        $suffix = substr($digits, -11);

        return UserList::where('user_id', $userId)
            ->where(function ($query) use ($digits, $suffix) {
                $query->where('phone', 'like', "%{$digits}%")
                    ->orWhere('phone', 'like', "%{$suffix}%");
            })
            ->where('whatsapp_opt_in', true)
            ->update(['whatsapp_opt_in' => false]);
    }

    /**
     * Salva mensagem recebida (do contato/usuário)
     * 🆕 Suporta mídia (imagem, vídeo, áudio, documento)
     */
    private function saveIncomingMessage(Chats $chat, Agents $agent, string $message, string $senderPhone, string $messageType, array $mediaData = []): bool
    {
        // 🆕 Verificar se mensagem já existe (controle de duplicatas)
        $existingMessage = null;
        if (!empty($mediaData['whatsappMessageId'])) {
            $existingMessage = Messages::where('agent_id', $agent->uuid)
                ->where('whatsapp_message_id', $mediaData['whatsappMessageId'])
                ->first();
        }

        if (!$existingMessage) {
            $existingMessage = Messages::where('chat_id', $chat->uuid)
                ->where('phone', $senderPhone)
                ->where('content', $message)
                ->where('direction', 'put')
                ->where('created_at', '>=', now()->subMinutes(5))
                ->first();
        }

        if ($existingMessage) {
            Log::info('Mensagem recebida duplicada detectada, ignorando', [
                'chat_id' => $chat->uuid,
                'sender_phone' => $senderPhone,
                'content' => $message
            ]);
            return false;
        }

        // 🆕 Preparar conteúdo com informações de mídia se houver
        $content = $message;
        if (!empty($mediaData['mediaPath'])) {
            $mediaInfo = [
                'type' => $messageType,
                'path' => $mediaData['mediaPath'],
                'mimetype' => $mediaData['mimetype'] ?? null,
                'filename' => $mediaData['filename'] ?? null,
                'caption' => $mediaData['caption'] ?? null
            ];

            if (!empty($mediaData['caption'])) {
                $content = $mediaData['caption'];
            }

            $content .= "\n[MEDIA:" . json_encode($mediaInfo) . "]";
        }

        Messages::create([
            'uuid' => (string) Str::uuid(),
            'chat_id' => $chat->uuid,
            'agent_id' => $agent->uuid,
            'name' => $chat->name ?: 'Contato WhatsApp',
            'content' => $content,
            'direction' => 'put', // Recebida
            'phone' => $senderPhone, // Número de quem enviou
            'audio' => $messageType === 'audio',
            'whatsapp_message_id' => $mediaData['whatsappMessageId'] ?? null,
            'credit_usage' => 0
        ]);

        // Atualiza última mensagem e horário do chat para atualizar a lista do painel
        $chat->update([
            'last_message' => Str::limit($content, 100),
            'time' => now()->format('H:i'),
        ]);

        Log::info('Mensagem recebida salva', [
            'chat_id' => $chat->uuid,
            'sender_phone' => $senderPhone,
            'agent_id' => $agent->uuid,
            'message_type' => $messageType,
            'has_media' => !empty($mediaData['mediaPath'])
        ]);

        return true;
    }

    /**
     * Salva mensagem enviada (do agente/assistente)
     */
    private function saveOutgoingMessage(Chats $chat, Agents $agent, string $message, string $receiverPhone, int $creditUsage): void
    {
        // 🆕 Verificar se resposta já existe (controle de duplicatas)
        $existingMessage = Messages::where('chat_id', $chat->uuid)
            ->where('phone', $receiverPhone)
            ->where('content', $message)
            ->where('direction', 'output')
            ->where('created_at', '>=', now()->subMinutes(5))
            ->first();

        if ($existingMessage) {
            Log::info('Mensagem enviada duplicada detectada, ignorando', [
                'chat_id' => $chat->uuid,
                'receiver_phone' => $receiverPhone,
                'content' => $message
            ]);
            return;
        }

        Messages::create([
            'uuid' => (string) Str::uuid(),
            'chat_id' => $chat->uuid,
            'agent_id' => $agent->uuid,
            'name' => $agent->name, // Nome do agente
            'content' => $message,
            'direction' => 'output', // Enviada
            'phone' => $receiverPhone, // Número para quem foi enviada
            'audio' => false,
            'credit_usage' => $creditUsage
        ]);

        // Atualiza última mensagem e horário do chat
        $chat->update([
            'last_message' => Str::limit($message, 100),
            'time' => now()->format('H:i'),
        ]);

        Log::info('Mensagem enviada salva', [
            'chat_id' => $chat->uuid,
            'receiver_phone' => $receiverPhone,
            'agent_id' => $agent->uuid,
            'credit_usage' => $creditUsage
        ]);
    }
}
