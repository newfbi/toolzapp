<?php

namespace App\Http\Livewire\Chats;

use App\Helpers\Uuid;
use App\Http\Controllers\Knowledge\Context\ContextController;
use App\Models\Agents;
use App\Models\Chats;
use App\Models\Messages;
use App\Models\Settings;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\On;
use Livewire\Component;

class ViewChatController extends Component
{
    public $chatId;
    public $audio;
    public $agent;
    public $agentId;
    public $userMessage;
    public $messages;
    public $isSending = false;
    private $contextController;
    protected $listeners = ['messageReceived'];

    public function boot(ContextController $contextController)
    {
        $this->contextController = $contextController;
    }

    public function mount($chatId = null, $agentId = null)
    {
        // Prioriza o uso do UUID do chat, permitindo abertura direta por rota ou query string
        $this->chatId = $chatId
            ?? request()->route('chatId')
            ?? request()->route('chatUuid')
            ?? request()->query('chat_uuid');

        // Caso o UUID ainda não esteja disponível, tenta recuperar via agentId (rota /chat/agent/{agentId})
        if (!$this->chatId) {
            $agentId = $agentId ?? request()->route('agentId') ?? request()->query('agent_id');

            if ($agentId) {
                $this->chatId = $this->getOrCreateChatForAgent($agentId);
            }
        }

        if (!$this->chatId) {
            return redirect()->to('agentes')->with('error', 'Parâmetros inválidos. Forneça chatId ou agentId.');
        }

        $chat = Chats::where('uuid', $this->chatId)
            ->where(function ($query) {
                $query->where('user_id', Auth::user()->uuid)
                    ->orWhereHas('agent', fn ($agentQuery) => $agentQuery->where('user_id', Auth::user()->uuid));
            })
            ->first();
        if (!$chat) {
            return redirect()->to('agentes')->with('error', 'Chat não encontrado ou sem permissão de acesso.');
        }

        $this->agent = Agents::where(['uuid' => $chat->agent_id, 'user_id' => Auth::user()->uuid])->first();
        if (!$this->agent) {
            return redirect()->to('agentes')->with('error', 'Agente não encontrado, verifique e tente novamente!');
        }
        $this->agentId = $this->agent->uuid;
        $this->messages = Messages::where('chat_id', $chat->uuid)->orderBy('created_at', 'asc')->get();

        // Atualiza última visualização do chat
        $chat->update([
            'time' => now()->format('H:i')
        ]);
    }

    /**
     * Cria um novo chat para um agente específico
     */
    private function getOrCreateChatForAgent(string $agentId): string
    {
        $user = Auth::user();

        Agents::where('uuid', $agentId)
            ->where('user_id', $user->uuid)
            ->firstOrFail();

        // Sempre cria um novo chat
        $uuid = new Uuid();
        $chatId = $uuid->limit(8);

        $chat = Chats::create([
            'uuid' => $chatId,
            'user_id' => $user->uuid,
            'agent_id' => $agentId,
            'name' => $user->name ?? 'Usuário',
            'status' => 'active',
            'is_pause' => 'false',
            'time' => now()->format('H:i')
        ]);

        return $chat->uuid;
    }

    public function render()
    {
        return view('livewire.chats.view');
    }

    #[On('sendAudioMessage')]
    public function sendMessage($audio = null)
    {
        if (empty($this->userMessage) && !$audio) {
            return;
        }

        $this->isSending = true;

        $audioUser = null;
        $userMessageText = $this->userMessage;

        // Processa áudio se fornecido
        if (isset($audio)) {
            try {
                // Decodifica o áudio Base64
                $base64Audio = $audio;
                $audioData = explode(',', $base64Audio);
                $audioContent = base64_decode(end($audioData));

                // Define o nome do arquivo
                $audioUser = 'audios/' . uniqid('audio_') . '.webm';

                // Salva o áudio no disco
                $filePath = public_path('assets/' . $audioUser);
                file_put_contents($filePath, $audioContent);

                // Transcreve o áudio usando ContextController
                $userMessageText = $this->contextController->transcribeAudio($filePath, $this->agent);

                if (!$userMessageText) {
                    $this->isSending = false;
                    session()->flash('error', 'Não foi possível transcrever o áudio. Tente novamente.');
                    return;
                }
            } catch (\Exception $e) {
                Log::error('Erro ao processar áudio: ' . $e->getMessage());
                $this->isSending = false;
                session()->flash('error', 'Erro ao processar áudio.');
                return;
            }
        }

        $chat = Chats::where('uuid', $this->chatId)
            ->where('user_id', Auth::user()->uuid)
            ->whereHas('agent', fn ($agentQuery) => $agentQuery->where('user_id', Auth::user()->uuid))
            ->first();
        if (!$chat) {
            $this->isSending = false;
            return redirect()->to('agentes')->with('error', 'Chat não encontrado ou sem permissão de acesso.');
        }

        // Verifica se chat está pausado
        if ($chat->is_pause === 'true') {
            Messages::create([
                'chat_id' => $chat->uuid,
                'agent_id' => $this->agentId,
                'name' => 'user',
                'content' => $userMessageText,
                'direction' => 'put',
                'audio' => $audioUser
            ]);
            $this->isSending = false;
            session()->flash('info', 'Chat está pausado.');
            return;
        }

        // Atualiza última mensagem e horário do chat
        $chat->update([
            'last_message' => $userMessageText,
            'time' => now()->format('H:i')
        ]);

        // Salvar a mensagem do usuário
        Messages::create([
            'chat_id' => $chat->uuid,
            'agent_id' => $this->agentId,
            'name' => 'user',
            'content' => $userMessageText,
            'direction' => 'put',
            'audio' => $audioUser
        ]);

        // Gera resposta do agente usando sistema de memória contextual
        try {
            // Resolve o orchestrator via container para evitar problemas de injeção
            $contextController = app(ContextController::class);
            $response = $contextController->generateResponse($chat, $this->agent, $userMessageText);
            $agentMessage = $response['message'];
            $tokens = $response['tokens'] ?? 0;

            // Processa captura de nome do usuário (intercepta formato técnico e substitui por resposta natural)
            if ($this->processUserNameCapture($chat, $response)) {
                // Nome foi capturado, resposta amigável já foi salva
                $this->messages = Messages::where('chat_id', $chat->uuid)->orderBy('created_at', 'asc')->get();
                $this->reset('userMessage');
                $this->dispatch('clearInput');
                $this->isSending = false;
                return;
            }

            $pdfProcessing = $this->processPdfPlaceholder($agentMessage);
            $agentMessage = $pdfProcessing['message'];
        } catch (\Exception $e) {
            Log::error("Erro ao gerar resposta: {$e->getMessage()}");
            $agentMessage = 'Desculpe, ocorreu um erro ao processar sua mensagem. Tente novamente.';
            $tokens = 0;
        }

        // Sintetiza áudio APENAS para WhatsApp, nunca para web chat
        $audioPath = null;
        $isWhatsApp = !empty($this->agent->channel) && strtolower($this->agent->channel) === 'whatsapp';
        if ($isWhatsApp and $audio !== null) {
            // Verifica se audio está habilitado (suporta boolean ou string)
            $audioEnabled = filter_var($this->agent->audio, FILTER_VALIDATE_BOOLEAN) ||
                (is_string($this->agent->audio) && in_array(strtolower($this->agent->audio), ['enabled', 'true', '1']));
            if ($audioEnabled) {
                $audioPath = $this->getSynthesizeAudio($agentMessage);
            }
        } // Web chat: $audioPath permanece null (não sintetiza)

        // Salvar a mensagem do agente
        Messages::create([
            'chat_id' => $chat->uuid,
            'agent_id' => $this->agentId,
            'name' => 'agente',
            'content' => $agentMessage,
            'direction' => 'output',
            'audio' => $audioPath,
            'credit_usage' => $tokens
        ]);

        // Créditos já foram debitados pelo ContextController

        // Atualiza lista de mensagens
        $this->messages = Messages::where('chat_id', $chat->uuid)->orderBy('created_at', 'asc')->get();

        // Limpar a mensagem do usuário
        $this->reset('userMessage');
        $this->dispatch('clearInput');
        $this->isSending = false;

        // Opcional: emitir evento Livewire local para atualização em outros componentes
        $this->dispatch('messageReceived', [
            'chat_id' => $chat->uuid,
            'content' => $agentMessage,
        ]);
    }

    /**
     * Sintetiza áudio usando OpenAI TTS
     */
    private function getSynthesizeAudio($agentMessage, string $gender = 'FEMALE')
    {
        try {
            $user = User::with(['api' => function ($q) {
                $q->where('service', 'OpenAI');
            }])->where('uuid', $this->agent->user_id)->first();

            if (!$user || !$user->api?->api_key) {
                Log::warning('Usuário sem chave de API para síntese de áudio');
                return null;
            }

            // Determina voz baseado no gênero do agente
            $voice = ($gender == 'FEMALE' || ($this->agent->gender ?? 'FEMALE') == 'FEMALE') ? 'nova' : 'onyx';

            $client = \OpenAI::client($user->api->api_key);

            // Gera áudio usando TTS
            $audioContent = $client->audio()->speech([
                'model' => 'tts-1',
                'input' => $agentMessage,
                'voice' => $voice,
            ]);

            // Gera um nome único para o arquivo de áudio
            $fileName = 'audios/agent_' . uniqid() . '.mp3';
            $filePath = public_path('assets/' . $fileName);

            // Salva o áudio no disco
            file_put_contents($filePath, $audioContent);

            return $fileName;
        } catch (\Exception $e) {
            Log::error("Erro ao sintetizar áudio: {$e->getMessage()}");
            return null;
        }
    }

    /**
     * Processa a captura de nome do usuário (similar ao WhatsApp)
     * Intercepta formato técnico e substitui por resposta natural
     */
    private function processUserNameCapture($chat, $response): bool
    {
        if (!filter_var($this->agent->nameUser ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return false;
        }

        // Detecta formato técnico "nome_do_usuario: [nome]" (pode estar em qualquer lugar da mensagem)
        if (preg_match('/nome_do_usuario:\\s*([^\n]+)/i', $response['message'], $match)) {
            $name = trim($match[1]);

            // Valida se é um nome real (não contém números, símbolos especiais)
            if (preg_match('/^[a-zA-ZÀ-ÿ\s]{2,50}$/u', $name)) {
                // Atualiza nome no chat
                $chat->name = $name;
                $chat->save();
                $chat->refresh();

                // Salva nome também no knowledge_base para uso nas conversas futuras
                $this->saveUserNameToKnowledgeBase($chat, $name);

                Log::info('Nome do usuário capturado no chat web:', [
                    'chat_id' => $chat->uuid,
                    'name' => $name
                ]);

                // Gera resposta amigável ao invés da técnica
                $friendlyResponse = "Prazer em conhecê-lo, {$name}! Como posso ajudá-lo hoje?";

                // Salva a resposta amigável (substituindo a mensagem técnica)
                Messages::create([
                    'chat_id' => $chat->uuid,
                    'agent_id' => $this->agentId,
                    'name' => 'agente',
                    'content' => $friendlyResponse,
                    'direction' => 'output',
                    'credit_usage' => $response['tokens'] ?? 0
                ]);

                return true; // Nome capturado, mensagem técnica substituída
            }
        }

        return false; // Não capturou nome, mantém resposta original
    }

    /**
     * Salva nome do usuário no knowledge_base para uso em conversas futuras
     */
    private function saveUserNameToKnowledgeBase($chat, $name)
    {
        try {
            $streamRepository = app(\App\Http\Controllers\Knowledge\Repositories\StreamRepository::class);
            $session = $streamRepository->getOrCreateStream($chat);

            // Lê knowledge_base atual
            $knowledge = $session->knowledge_base ?? [];
            if (is_string($knowledge)) {
                $decoded = json_decode($knowledge, true);
                $knowledge = json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? $decoded : [];
            }

            // Atualiza informações pessoais com o nome
            if (!isset($knowledge['informacoes_pessoais'])) {
                $knowledge['informacoes_pessoais'] = [];
            }
            $knowledge['informacoes_pessoais']['nome'] = $name;

            // Salva knowledge_base atualizado
            $session->knowledge_base = $knowledge;
            $streamRepository->save($session);

            Log::info('Nome salvo no knowledge_base (chat web)', [
                'chat_id' => $chat->uuid,
                'name' => $name
            ]);
        } catch (\Exception $e) {
            Log::warning('Erro ao salvar nome no knowledge_base: ' . $e->getMessage());
            // Não falha o fluxo se houver erro
        }
    }

    public function messageReceived($payload)
    {
        // Permite diferentes formatos: ['chat_id'=>..] ou ['payload'=>['chat_id'=>..]]
        $data = $payload;
        if (is_array($payload) && isset($payload['payload']) && is_array($payload['payload'])) {
            $data = $payload['payload'];
        }
        if (!is_array($data) || !isset($data['chat_id'])) {
            return;
        }
        // Verifica se a mensagem pertence ao chat atual
        if ($data['chat_id'] === $this->chatId) {
            $this->messages = Messages::where('chat_id', $data['chat_id'])->orderBy('created_at', 'asc')->get();
        }
    }

    /**
     * Processa placeholder de envio de PDF, removendo comandos técnicos e anexando link.
     */
    private function processPdfPlaceholder(?string $agentMessage): array
    {
        if (empty($agentMessage)) {
            return [
                'message' => '',
                'pdf_sent' => false,
            ];
        }

        $pattern = '/(\{\{\s*enviar_?pdf\s*\}\}|\[\[\s*enviar_?pdf\s*\]\]|enviar_?pdf\s*:?.*)/i';

        $pdfDetected = preg_match($pattern, $agentMessage, $matches, PREG_OFFSET_CAPTURE);

        if (!$pdfDetected) {
            return [
                'message' => trim($agentMessage),
                'pdf_sent' => false,
            ];
        }

        $sanitized = substr($agentMessage, 0, $matches[0][1]);
        $sanitized = trim(preg_replace("/\n{2,}/", "\n\n", $sanitized));

        $pdfUrl = url('pdf/book_view.pdf');
        $agentName = trim($this->agent->name ?? '');

        $ackMessage = $agentName !== ''
            ? "Acabei de te enviar o PDF com todas as informações. Caso não abra, me avise, por favor."
            : "Acabei de te enviar o PDF com todas as informações. Caso não abra, me avise, por favor.";

        $linkMessage = "Link direto para baixar: {$pdfUrl}";

        $finalMessage = $sanitized !== ''
            ? $sanitized . "\n\n" . $ackMessage . "\n" . $linkMessage
            : $ackMessage . "\n" . $linkMessage;

        return [
            'message' => trim($finalMessage),
            'pdf_sent' => true,
        ];
    }
}
