<?php

namespace App\Http\Controllers\Whatsapp\Instances;

use App\Http\Controllers\Controller;
use App\Models\WhatsappInstance;
use App\Models\Chats;
use App\Models\Messages;
use App\Models\Agents;
use App\Models\WhatsappDispatchItem;
use App\Helpers\Uuid;
use App\Services\ApiFullService;
use App\Services\WhatsappMessageFormatter;
use App\Http\Controllers\Knowledge\Context\ContextController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class InstanceWebhookController extends Controller
{
    private $contextController;
    private $apiFullService;
    private $whatsappMessageFormatter;

    public function __construct(
        ContextController $contextController,
        ApiFullService $apiFullService,
        WhatsappMessageFormatter $whatsappMessageFormatter
    ) {
        $this->contextController = $contextController;
        $this->apiFullService = $apiFullService;
        $this->whatsappMessageFormatter = $whatsappMessageFormatter;
    }

    /**
     * Endpoint único e autenticado por assinatura para os webhooks Z-API.
     * A assinatura fica na URL e não expõe tokens da instância.
     */
    public function handleZApiWebhook(Request $request, string $instanceUuid, string $signature)
    {
        $expected = hash_hmac('sha256', $instanceUuid, (string) config('app.key'));
        if (!hash_equals($expected, $signature)) {
            return response()->json(['status' => 'error', 'message' => 'Webhook não autorizado.'], 401);
        }

        $instance = WhatsappInstance::where('uuid', $instanceUuid)
            ->where('provider', 'zapi')
            ->first();
        if (!$instance) {
            return response()->json(['status' => 'error', 'message' => 'Instância Z-API não encontrada.'], 404);
        }

        $payload = $request->all();
        $type = (string) ($payload['type'] ?? '');

        try {
            if ($type === 'DeliveryCallback') {
                $this->processZApiDelivery($payload, $instance);
                return response()->json(['value' => true]);
            }

            if ($type === 'MessageStatusCallback') {
                $this->processZApiMessageStatus($payload, $instance);
                return response()->json(['value' => true]);
            }

            if (in_array($type, ['DisconnectedCallback', 'disconnected'], true)) {
                $instance->update([
                    'status' => 'disconnected',
                    'phone_number' => null,
                    'connected_at' => null,
                    'last_activity' => now(),
                    'error_message' => $payload['error'] ?? 'Z-API informou desconexão.',
                ]);
                return response()->json(['value' => true]);
            }

            if (in_array($type, ['ConnectedCallback', 'connected'], true)) {
                $instance->update([
                    'status' => 'connected',
                    'error_message' => null,
                    'connected_at' => $instance->connected_at ?: now(),
                    'last_activity' => now(),
                ]);
                return response()->json(['value' => true]);
            }

            if ($type === 'ReceivedCallback') {
                // O webhook pode ser configurado para também receber eventos
                // fromMe. Eles nunca devem iniciar o agente nem criar loop.
                $receivedData = is_array($payload['data'] ?? null) ? $payload['data'] : [];
                $fromMe = $payload['fromMe']
                    ?? $payload['from_me']
                    ?? $payload['isFromMe']
                    ?? $receivedData['fromMe']
                    ?? $receivedData['from_me']
                    ?? $receivedData['isFromMe']
                    ?? false;

                if (filter_var($fromMe, FILTER_VALIDATE_BOOLEAN)) {
                    return response()->json(['value' => true]);
                }

                return $this->processReceivedMessage($payload, $instance);
            }

            $instance->touch();
            return response()->json(['value' => true]);
        } catch (\Throwable $e) {
            Log::error('Erro no webhook Z-API', [
                'instance_uuid' => $instanceUuid,
                'type' => $type,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['status' => 'error', 'message' => 'Falha ao processar webhook.'], 500);
        }
    }

    private function processZApiDelivery(array $payload, WhatsappInstance $instance): void
    {
        $messageId = $payload['messageId'] ?? $payload['zaapId'] ?? null;
        if (!$messageId) {
            return;
        }

        $item = WhatsappDispatchItem::where('whatsapp_message_id', $messageId)
            ->whereHas('dispatch', fn ($query) => $query->where('agent_id', $instance->agent_id))
            ->first();
        $error = $payload['error'] ?? null;
        if ($item) {
            $item->update($error ? [
                'status' => 'failed',
                'delivery_status' => 'failed',
                'error_message' => mb_substr((string) $error, 0, 500),
            ] : [
                'delivery_status' => 'delivered',
                'delivered_at' => now(),
                'error_message' => null,
            ]);

            if ($item->dispatch) {
                app(\App\Services\WhatsappDispatchService::class)->refreshDispatchCounters($item->dispatch);
            }
        }

        Messages::where('agent_id', $instance->agent_id)
            ->where('whatsapp_message_id', $messageId)
            ->update([
                'delivery_status' => $error ? 'failed' : 'delivered',
                'delivered_at' => $error ? null : now(),
            ]);

        $instance->update(['last_activity' => now()]);
    }

    private function processZApiMessageStatus(array $payload, WhatsappInstance $instance): void
    {
        $status = strtoupper((string) ($payload['status'] ?? ''));
        $ids = is_array($payload['ids'] ?? null) ? $payload['ids'] : [];
        $ids = array_values(array_filter(array_map(function ($id) {
            return is_array($id) ? ($id['messageId'] ?? $id['id'] ?? null) : $id;
        }, $ids)));

        $deliveryStatus = match ($status) {
            'READ', 'READ_BY_ME' => 'read',
            'PLAYED' => 'played',
            'RECEIVED' => 'delivered',
            'SENT' => 'sent',
            default => strtolower($status ?: 'unknown'),
        };

        foreach ($ids as $messageId) {
            $item = WhatsappDispatchItem::where('whatsapp_message_id', $messageId)
                ->whereHas('dispatch', fn ($query) => $query->where('agent_id', $instance->agent_id))
                ->first();
            if ($item) {
                $data = ['delivery_status' => $deliveryStatus];
                if ($deliveryStatus === 'delivered') {
                    $data['delivered_at'] = $item->delivered_at ?: now();
                }
                if (in_array($deliveryStatus, ['read', 'played'], true)) {
                    $data['read_at'] = $item->read_at ?: now();
                }
                $item->update($data);
                if ($item->dispatch) {
                    app(\App\Services\WhatsappDispatchService::class)->refreshDispatchCounters($item->dispatch);
                }
            }

            $message = Messages::where('agent_id', $instance->agent_id)
                ->where('whatsapp_message_id', $messageId)
                ->first();
            if ($message) {
                $data = ['delivery_status' => $deliveryStatus];
                if ($deliveryStatus === 'delivered') {
                    $data['delivered_at'] = $message->delivered_at ?: now();
                }
                if (in_array($deliveryStatus, ['read', 'played'], true)) {
                    $data['read_at'] = $message->read_at ?: now();
                }
                $message->update($data);
            }
        }

        $instance->update(['last_activity' => now()]);
    }

    /**
     * Processa webhook para uma instância específica
     */
    public function handleWebhook(Request $request, string $instanceId)
    {
        try {
            $payload = $request->all();
            $instance = WhatsappInstance::where('instance_id', $instanceId)->first();

            if (!$instance) {
                Log::warning("Instância WhatsApp não encontrada: {$instanceId}");
                return response()->json([
                    'status' => 'error',
                    'message' => 'Instância não encontrada'
                ], 404);
            }

            // Verificar se a instância está ativa
            if (!$instance->isConnected()) {
                Log::warning("Instância WhatsApp desconectada: {$instanceId}");
                return response()->json([
                    'status' => 'error',
                    'message' => 'Instância desconectada'
                ], 400);
            }

            // Processar mensagem recebida
            if ($this->isReceivedMessage($payload)) {
                return $this->processReceivedMessage($payload, $instance);
            }

            // Processar status de mensagem
            if ($this->isMessageStatus($payload)) {
                return $this->processMessageStatus($payload, $instance);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Webhook recebido'
            ]);
        } catch (\Exception $e) {
            Log::error("Erro ao processar webhook da instância {$instanceId}: {$e->getMessage()}");
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    private function isReceivedMessage($payload)
    {
        return isset($payload['type']) && in_array($payload['type'], ['ReceivedCallback', 'received_message']) ||
            isset($payload['phone']) && isset($payload['message']) ||
            isset($payload['messageType']);
    }

    private function isMessageStatus($payload)
    {
        return isset($payload['type']) && $payload['type'] === 'message_status' && isset($payload['data']);
    }

    private function processReceivedMessage($payload, WhatsappInstance $instance)
    {
        $uuid = new Uuid();
        $uu_id = $uuid->limit(8);

        // Extrair dados do payload
        if (isset($payload['data'])) {
            $data = $payload['data'];
        } else {
            $data = $payload;
        }

        $userNumber = $this->formatPhoneNumber($data['phone'] ?? $data['from'] ?? '');
        $userMessage = $data['text']['message'] ?? $data['message'] ?? $data['text'] ?? $data['body'] ?? '';
        $mediaUrl = $data['media'] ?? $data['url'] ?? null;
        $mimeType = $data['mimetype'] ?? $data['mimeType'] ?? null;
        $whatsappMessageId = $data['messageId'] ?? $data['message_id'] ?? $data['id'] ?? null;

        if ($whatsappMessageId && Messages::where('agent_id', $instance->agent_id)
            ->where('whatsapp_message_id', $whatsappMessageId)
            ->exists()) {
            return response()->json(['status' => 'success', 'message' => 'Mensagem duplicada ignorada']);
        }

        // Validações básicas
        if (empty($userNumber) || (empty($userMessage) && empty($mediaUrl))) {
            return response()->json([
                'status' => 'success',
                'message' => 'Dados insuficientes ou mensagem vazia'
            ]);
        }

        // Encontrar ou criar chat apropriado
        $chat = $this->findOrCreateChat($instance, $userNumber, $uu_id, $data);

        if (!$chat) {
            return response()->json([
                'status' => 'error',
                'message' => 'Não foi possível criar/encontrar chat'
            ], 500);
        }

        // Verificar se chat está pausado
        if ($chat->is_pause === 'true') {
            $this->saveMessage($chat->uuid, $chat->agent->uuid ?? null, 'user', $userMessage ?: 'Mídia enviada', 'put', $userNumber, 0, $whatsappMessageId);
            return response()->json(['status' => 'success', 'message' => 'Chat pausado']);
        }

        // Processar diferentes tipos de mensagem
        if (!empty($mediaUrl) && str_starts_with((string) $mimeType, 'audio/')) {
            return $this->processAudioMessage($instance, $chat, $userNumber, $data, $uu_id, $whatsappMessageId);
        }

        if (!empty($mediaUrl) && str_starts_with((string) $mimeType, 'image/')) {
            return $this->processImageMessage($instance, $chat, $userNumber, $data, $uu_id, $whatsappMessageId);
        }

        if (!empty($userMessage)) {
            return $this->processTextMessage($instance, $chat, $userNumber, $userMessage, $uu_id, $data, $whatsappMessageId);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Tipo de mensagem não suportado'
        ]);
    }

    private function findOrCreateChat(WhatsappInstance $instance, string $userNumber, string $uu_id, array $data): ?Chats
    {
        // Se a instância está vinculada a um chat específico, usar esse chat
        if ($instance->chat_id) {
            $chat = $instance->chat;

            // Verificar se o chat pertence ao usuário da instância
            if ($chat && $chat->user_id === $instance->user_id) {
                return $chat;
            }
        }

        // Caso contrário, tentar encontrar chat existente ou criar novo
        // Primeiro tentar encontrar por agent_id + user_id
        $agent = $instance->chat ? $instance->chat->agent : $instance->agent;

        if ($agent) {
            $chat = Chats::firstOrCreate(
                [
                    'agent_id' => $agent->uuid,
                    'user_id' => $userNumber,
                ],
                [
                    'uuid' => $uu_id,
                    'user_id' => $userNumber,
                    'agent_id' => $agent->uuid,
                    'name' => $data['senderName'] ?? $data['chatName'] ?? 'Usuário WhatsApp',
                    'status' => 'active',
                    'is_pause' => 'false',
                    'last_message' => 'Mensagem recebida',
                    'time' => now()->format('H:i')
                ]
            );

            $chat->update([
                'last_message' => 'Mensagem recebida',
                'time' => now()->format('H:i')
            ]);

            return $chat;
        }

        // Se não há agente associado, logar e retornar null
        Log::warning("Instância {$instance->instance_id} não possui agente associado via chat");
        return null;
    }

    private function processTextMessage(WhatsappInstance $instance, Chats $chat, string $userNumber, string $userMessage, string $uu_id, array $data, ?string $whatsappMessageId = null)
    {
        $agent = $chat->agent;
        if (!$agent || !in_array($agent->status, ['active', 'enabled'])) {
            Log::warning("Agente inativo ou não encontrado para chat {$chat->uuid}");
            return response()->json(['status' => 'success', 'message' => 'Agente inativo']);
        }

        // Verificações de rate limit e tamanho
        if ($this->isRateLimited($userNumber)) {
            return response()->json(['status' => 'success', 'message' => 'Rate limit excedido']);
        }

        if (strlen($userMessage) > ($agent->max_characters ?: 1000)) {
            $this->sendMessage($instance, $userNumber, 'Mensagem muito longa. Por favor, seja mais conciso.');
            return response()->json(['status' => 'success', 'message' => 'Mensagem muito longa']);
        }

        // Preparar contexto e gerar resposta
        $contextMessage = $this->prepareContextMessage($chat, $userMessage, $data);
        $response = $this->contextController->generateResponse($chat, $agent, $contextMessage);

        // Processar captura de nome se necessário
        if ($this->processUserNameCapture($agent, $chat, $response, $instance, $userNumber)) {
            $this->saveMessage($chat->uuid, $agent->uuid, 'user', $userMessage, 'put', $userNumber, 0, $whatsappMessageId);
            return response()->json(['status' => 'success', 'message' => 'Nome capturado']);
        }

        // Salvar mensagem do usuário com prompt_tokens (input)
        $promptTokens = $response['prompt_tokens'] ?? 0;
        $this->saveMessage($chat->uuid, $agent->uuid, 'user', $userMessage, 'put', $userNumber, $promptTokens, $whatsappMessageId);

        // Processar resposta do agente
        $agentMessage = $this->sanitizeAgentMessage($response['message'] ?? '');
        $pdfFallback = $this->handlePdfRequest($agentMessage, $agent, $instance, $userNumber);

        if ($pdfFallback) {
            $agentMessage = $pdfFallback;
        }

        if (!empty($agentMessage)) {
            $this->contextController->replaceLastAssistantMessage($chat, $agentMessage);
        }

        // Salvar mensagem do agente com completion_tokens (output)
        $completionTokens = $response['completion_tokens'] ?? 0;
        $outputMessage = $this->saveMessage($chat->uuid, $agent->uuid, 'agente', $agentMessage, 'output', $userNumber, $completionTokens);

        // Enviar resposta via WhatsApp e persistir o resultado real do provedor
        $this->updateMessageDelivery(
            $outputMessage,
            $this->sendMessage($instance, $userNumber, $agentMessage)
        );

        return response()->json(['status' => 'success', 'message' => 'Mensagem processada']);
    }

    private function processAudioMessage(WhatsappInstance $instance, Chats $chat, string $userNumber, array $data, string $uu_id, ?string $whatsappMessageId = null)
    {
        $agent = $chat->agent;
        if (!$agent) {
            return response()->json(['status' => 'error', 'message' => 'Agente não encontrado']);
        }

        $mediaUrl = $data['media'] ?? $data['url'] ?? null;
        if (!$mediaUrl) {
            $this->sendMessage($instance, $userNumber, 'Não consegui processar o áudio.');
            return response()->json(['status' => 'success', 'message' => 'URL de áudio não encontrada']);
        }

        if ($agent->audio !== 'true') {
            $this->sendMessage($instance, $userNumber, 'Desculpe, não consigo processar áudios no momento.');
            return response()->json(['status' => 'success', 'message' => 'Áudio não suportado']);
        }

        $transcription = $this->contextController->transcribeAudio($mediaUrl, $agent);
        if (!$transcription) {
            $this->sendMessage($instance, $userNumber, 'Não consegui entender o áudio. Tente novamente.');
            return response()->json(['status' => 'success', 'message' => 'Falha na transcrição']);
        }

        $contextMessage = $this->prepareContextMessage($chat, $transcription, $data);
        $response = $this->contextController->generateResponse($chat, $agent, $contextMessage);

        // Salvar mensagem do usuário (áudio) com prompt_tokens
        $promptTokens = $response['prompt_tokens'] ?? 0;
        $this->saveMessage($chat->uuid, $agent->uuid, 'user', "Áudio: {$transcription}", 'put', $userNumber, $promptTokens, $whatsappMessageId);

        $agentMessage = $this->sanitizeAgentMessage($response['message'] ?? '');
        if (!empty($agentMessage)) {
            $this->contextController->replaceLastAssistantMessage($chat, $agentMessage);
        }

        // Salvar mensagem do agente com completion_tokens
        $completionTokens = $response['completion_tokens'] ?? 0;
        $outputMessage = $this->saveMessage($chat->uuid, $agent->uuid, 'agente', $agentMessage, 'output', $userNumber, $completionTokens);
        $this->updateMessageDelivery(
            $outputMessage,
            $this->sendMessage($instance, $userNumber, $agentMessage)
        );

        return response()->json(['status' => 'success', 'message' => 'Áudio processado']);
    }

    private function processImageMessage(WhatsappInstance $instance, Chats $chat, string $userNumber, array $data, string $uu_id, ?string $whatsappMessageId = null)
    {
        $agent = $chat->agent;
        if (!$agent) {
            return response()->json(['status' => 'error', 'message' => 'Agente não encontrado']);
        }

        $mediaUrl = $data['media'] ?? $data['url'] ?? null;
        if (!$mediaUrl) {
            $this->sendMessage($instance, $userNumber, 'Não consegui processar a imagem.');
            return response()->json(['status' => 'success', 'message' => 'URL de imagem não encontrada']);
        }

        if ($agent->midia !== 'true') {
            $this->sendMessage($instance, $userNumber, 'Desculpe, não estou recebendo imagens no momento.');
            return response()->json(['status' => 'success', 'message' => 'Imagem não suportada']);
        }

        $contextMessage = $this->prepareContextMessage($chat, 'Imagem recebida', $data);
        $response = $this->contextController->generateResponse($chat, $agent, $contextMessage);

        // Salvar mensagem do usuário (imagem) com prompt_tokens
        $promptTokens = $response['prompt_tokens'] ?? 0;
        $this->saveMessage($chat->uuid, $agent->uuid, 'user', 'Imagem recebida', 'put', $userNumber, $promptTokens, $whatsappMessageId);

        $agentMessage = $this->sanitizeAgentMessage($response['message'] ?? '');
        if (!empty($agentMessage)) {
            $this->contextController->replaceLastAssistantMessage($chat, $agentMessage);
        }

        // Salvar mensagem do agente com completion_tokens
        $completionTokens = $response['completion_tokens'] ?? 0;
        $outputMessage = $this->saveMessage($chat->uuid, $agent->uuid, 'agente', $agentMessage, 'output', $userNumber, $completionTokens);
        $this->updateMessageDelivery(
            $outputMessage,
            $this->sendMessage($instance, $userNumber, $agentMessage)
        );

        return response()->json(['status' => 'success', 'message' => 'Imagem processada']);
    }

    private function processMessageStatus($payload, WhatsappInstance $instance)
    {
        $data = $payload['data'];
        $messageId = $data['id'] ?? null;
        $status = $data['status'] ?? null;
        $phone = $this->formatPhoneNumber($data['phone'] ?? '');

        if ($messageId && $status) {
            Messages::where('message_id', $messageId)
                ->where('phone', $phone)
                ->update(['status' => strtoupper($status)]);
        }

        return response()->json(['status' => 'success', 'message' => 'Status atualizado']);
    }

    private function sendMessage(WhatsappInstance $instance, string $phone, string $message): array
    {
        try {
            if ($instance->provider === 'zapi') {
                return app(\App\Services\WhatsappProviderService::class)->sendText(
                    $instance,
                    $phone,
                    $message
                );
            } elseif ($instance->provider === 'apifull') {
                $result = $this->apiFullService->sendTextMessage($phone, $message);
                return [
                    'success' => empty($result['error']),
                    'message_id' => $result['messageId'] ?? $result['message_id'] ?? $result['id'] ?? null,
                    'delivery_status' => empty($result['error']) ? 'accepted' : 'failed',
                    'error' => $result['error'] ?? null,
                    'raw' => $result,
                ];
            }

            return ['success' => false, 'delivery_status' => 'failed', 'error' => 'Provedor de envio não configurado.'];
        } catch (\Exception $e) {
            Log::error("Erro ao enviar mensagem via {$instance->provider}: {$e->getMessage()}");
            return [
                'success' => false,
                'delivery_status' => 'failed',
                'error' => $e->getMessage(),
            ];
        }
    }

    private function saveMessage(string $chatId, ?string $agentId, string $type, string $message, string $route, string $phone, int $tokens, ?string $whatsappMessageId = null): Messages
    {
        return Messages::create([
            'chat_id' => $chatId,
            'agent_id' => $agentId,
            'name' => $message,
            'content' => $message,
            'credit_usage' => $tokens,
            'direction' => $route === 'output' ? 'output' : 'put',
            'phone' => $phone,
            'whatsapp_message_id' => $whatsappMessageId,
        ]);
    }

    private function updateMessageDelivery(Messages $message, array $result): void
    {
        $success = ($result['success'] ?? false) === true;
        $data = [
            'delivery_status' => $result['delivery_status'] ?? ($success ? 'accepted' : 'failed'),
        ];

        if (!empty($result['message_id'])) {
            $data['whatsapp_message_id'] = $result['message_id'];
        }

        $message->update($data);
    }

    private function prepareContextMessage(Chats $chat, string $message, array $data): string
    {
        $contextMessage = $message;

        if ($this->hasValidUserName($chat, $data['senderName'] ?? null, $data['chatName'] ?? null)) {
            $contextMessage = "O usuário se chama {$chat->name}. " . $message;
        }

        return $contextMessage;
    }

    private function hasValidUserName(Chats $chat, ?string $senderName, ?string $chatName): bool
    {
        $name = trim($chat->name ?? '');
        if ($name === '' || $name === 'Usuário') {
            $candidate = trim($senderName ?: $chatName ?: '');
            return $candidate !== '' && $candidate !== 'Usuário';
        }
        return true;
    }

    private function processUserNameCapture(Agents $agent, Chats $chat, array $response, WhatsappInstance $instance, string $userNumber): bool
    {
        $agentMessage = $response['message'] ?? '';
        if (!preg_match('/nome_do_usuario\s*:\s*(.+)/i', $agentMessage, $matches)) {
            return false;
        }

        $capturedName = trim($matches[1]);
        if ($capturedName === '') {
            return false;
        }

        $chat->name = $capturedName;
        $chat->save();

        $friendlyAck = 'Perfeito, ' . $capturedName . '! Prazer em te atender. Como posso te ajudar?';
        $outputMessage = $this->saveMessage($chat->uuid, $agent->uuid, 'agente', $friendlyAck, 'output', $userNumber, 0);
        $this->updateMessageDelivery(
            $outputMessage,
            $this->sendMessage($instance, $userNumber, $friendlyAck)
        );

        return true;
    }

    private function isRateLimited(string $phone): bool
    {
        $messagesInLastMinute = Messages::where('phone', $phone)
            ->where('created_at', '>=', now()->subMinute())
            ->count();

        return $messagesInLastMinute > 5;
    }

    private function sanitizeAgentMessage(?string $message): string
    {
        if ($message === null) {
            return '';
        }

        // Remover placeholders de PDF e nome
        $message = preg_replace('/(\{\{\s*enviar_?pdf\s*\}\}|\[\[\s*enviar_?pdf\s*\]\]|enviar_?pdf\s*:?.*)/i', '', $message);
        $message = preg_replace('/(nome_do_usuario)\s*:\s*/i', '', $message);

        return $this->whatsappMessageFormatter->format($message);
    }

    private function handlePdfRequest(?string $agentMessage, Agents $agent, WhatsappInstance $instance, string $userNumber): ?string
    {
        if (empty($agentMessage) || !preg_match('/enviar_pdf/i', $agentMessage)) {
            return null;
        }

        $pdfPath = public_path('pdf/book_view.pdf');
        if (!file_exists($pdfPath)) {
            return 'Tentei enviar o PDF, mas houve um problema. Podemos continuar por aqui?';
        }

        try {
            $documentUrl = url('pdf/book_view.pdf');
            $fileName = 'material.pdf';

            if ($instance->provider === 'zapi') {
                $result = app(\App\Services\WhatsappProviderService::class)->sendDocument(
                    $instance,
                    $userNumber,
                    $documentUrl,
                    $fileName,
                    ['caption' => 'Material em PDF com as informações solicitadas.']
                );

                if (($result['success'] ?? false) !== true) {
                    Log::warning('Z-API não confirmou o envio do PDF', [
                        'instance_uuid' => $instance->uuid,
                        'error' => $result['error'] ?? 'erro desconhecido',
                    ]);
                    return 'Tive um problema ao enviar o PDF. Podemos continuar a conversa?';
                }
            }

            return 'Enviei o material em PDF. Caso não receba, me avise!';
        } catch (\Exception $e) {
            Log::error("Erro ao enviar PDF: {$e->getMessage()}");
            return 'Tive um problema ao enviar o PDF. Podemos continuar a conversa?';
        }
    }

    private function formatPhoneNumber(string $phone): string
    {
        $digits = preg_replace('/[^0-9]/', '', $phone);
        if (!str_starts_with($digits, '55')) {
            $digits = '55' . $digits;
        }
        return $digits;
    }
}
