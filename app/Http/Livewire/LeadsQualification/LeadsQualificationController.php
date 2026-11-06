<?php

namespace App\Http\Livewire\LeadsQualification;

use App\Http\Controllers\Knowledge\Repositories\StreamRepository;
use App\Http\Controllers\Knowledge\Services\LeadQualificationService;
use App\Models\Chats;
use App\Models\MemoryStream;
use App\Models\Agents;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Component;
use Livewire\WithPagination;

class LeadsQualificationController extends Component
{
    use WithPagination;

    public $user;
    public $userId;
    public $filterSearch = '';
    public $filterQualified = '';
    public $selectedChat = null;
    public $showDetailsModal = false;
    public $perPage = 15;
    public $processingChatId = null; // Rastreia qual chat está sendo processado
    public $isProcessing = false; // Flag geral de processamento
    protected $paginationTheme = 'bootstrap';

    public function mount()
    {
        $this->user = Auth::user();
        $this->userId = $this->user->uuid;
        view()->share('title', 'Qualificação de Leads');
    }

    public function render()
    {
        $chats = $this->getChatsQuery()->paginate($this->perPage);
        $stats = $this->getStatistics();

        return view('livewire.leads_qualification.index', compact('chats', 'stats'));
    }

    /**
     * Obtém instância do StreamRepository
     */
    private function getStreamRepository(): StreamRepository
    {
        return app(StreamRepository::class);
    }

    /**
     * Obtém instância do LeadQualificationService
     */
    private function getLeadQualificationService(): LeadQualificationService
    {
        return app(LeadQualificationService::class);
    }

    /**
     * Obtém query de chats com qualificação
     */
    private function getChatsQuery()
    {
        $query = Chats::where('user_id', $this->userId)
            ->with('agent')
            ->withCount('messages');

        // Filtro de busca
        if (filled($this->filterSearch)) {
            $search = trim($this->filterSearch);
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('last_message', 'like', "%{$search}%");
            });
        }

        // Filtro por qualificação (precisa verificar na MemoryStream)
        if (filled($this->filterQualified)) {
            $qualifiedChatIds = MemoryStream::whereHas('chat', function ($q) {
                $q->where('user_id', $this->userId);
            })
                ->select(['chat_id', 'lead_qualification'])
                ->whereNotNull('lead_qualification')
                ->get()
                ->filter(function ($stream) {
                    $qualification = $stream->lead_qualification;
                    // O cast do modelo já retorna como array, mas mantém compatibilidade com dados antigos
                    if (is_string($qualification)) {
                        $qualification = json_decode($qualification, true);
                    }
                    if (!is_array($qualification)) {
                        return false;
                    }
                    $isQualified = $qualification['qualified'] ?? false;

                    if ($this->filterQualified === 'qualified') {
                        return $isQualified === true;
                    } elseif ($this->filterQualified === 'not_qualified') {
                        return $isQualified === false;
                    }
                    return true;
                })
                ->pluck('chat_id')
                ->toArray();

            if ($this->filterQualified === 'qualified' || $this->filterQualified === 'not_qualified') {
                $query->whereIn('uuid', $qualifiedChatIds);
            }
        }

        return $query->orderBy('created_at', 'desc');
    }

    /**
     * Obtém estatísticas gerais de qualificação
     */
    private function getStatistics(): array
    {
        $allChats = Chats::where('user_id', $this->userId)
            ->whereNotNull('phone')
            ->get();

        // Carrega as memórias dos chats em lote. Consultar o repositório
        // dentro do foreach criava uma consulta adicional por lead a cada
        // atualização do painel.
        $streamsByChat = MemoryStream::whereIn('chat_id', $allChats->pluck('uuid'))
            ->latest('updated_at')
            ->get()
            ->groupBy('chat_id')
            ->map(static fn ($streams) => $streams->first());

        $totalChats = $allChats->count();
        $qualifiedCount = 0;
        $notQualifiedCount = 0;
        $totalScore = 0;
        $scoreCount = 0;
        $conversionCount = 0;

        foreach ($allChats as $chat) {
            $stream = $streamsByChat->get($chat->uuid);

            if ($stream && $stream->lead_qualification) {
                $qualification = $stream->lead_qualification;
                // O cast do modelo já retorna como array, mas mantém compatibilidade com dados antigos
                if (is_string($qualification)) {
                    $qualification = json_decode($qualification, true);
                }

                if (is_array($qualification)) {
                    $isQualified = $qualification['qualified'] ?? false;
                    $score = $qualification['score'] ?? 0;

                    if ($isQualified) {
                        $qualifiedCount++;
                    } else {
                        $notQualifiedCount++;
                    }

                    if ($score > 0) {
                        $totalScore += $score;
                        $scoreCount++;
                    }
                }
            }

            // Conta conversões (compatível com string 'true' ou boolean true)
            $isConverted = $chat->conversion === true || $chat->conversion === 'true' || $chat->conversion === 1;
            if ($isConverted) {
                $conversionCount++;
            }
        }

        $averageScore = $scoreCount > 0 ? round($totalScore / $scoreCount, 1) : 0;
        $qualifiedPercentage = $totalChats > 0 ? round(($qualifiedCount / $totalChats) * 100, 1) : 0;
        $conversionPercentage = $totalChats > 0 ? round(($conversionCount / $totalChats) * 100, 1) : 0;

        return [
            'total_chats' => $totalChats,
            'qualified_count' => $qualifiedCount,
            'not_qualified_count' => $notQualifiedCount,
            'average_score' => $averageScore,
            'qualified_percentage' => $qualifiedPercentage,
            'conversion_count' => $conversionCount,
            'conversion_percentage' => $conversionPercentage,
            'analyzed_count' => $qualifiedCount + $notQualifiedCount,
        ];
    }

    /**
     * Obtém dados de qualificação de um chat específico
     */
    public function getChatQualification($chatId): ?array
    {
        $chat = Chats::where('uuid', $chatId)
            ->where('user_id', $this->userId)
            ->first();

        if (!$chat) {
            return null;
        }

        $streamRepository = $this->getStreamRepository();
        $stream = $streamRepository->getLastStream($chat);

        if (!$stream || !$stream->lead_qualification) {
            return null;
        }

        $qualification = $stream->lead_qualification;
        // O cast do modelo já retorna como array, mas mantém compatibilidade com dados antigos
        if (is_string($qualification)) {
            $qualification = json_decode($qualification, true);
        }
        if (!is_array($qualification)) {
            return null;
        }

        // Obtém dados da knowledge_base também
        $knowledge = $stream->knowledge_base ?? [];
        if (is_string($knowledge)) {
            $knowledge = json_decode($knowledge, true);
        }
        $knowledgeQualification = $knowledge['qualificacao_lead'] ?? null;

        // Combina dados
        $result = [
            'qualified' => $qualification['qualified'] ?? false,
            'score' => $qualification['score'] ?? 0,
            'reasons' => $qualification['reasons'] ?? [],
            'analysis' => $qualification['analysis'] ?? '',
            'confidence' => $qualification['confidence'] ?? null,
            'indicators' => $qualification['indicators'] ?? [],
            'criteria_met' => $qualification['criteria_met'] ?? [],
        ];

        // Adiciona dados da knowledge_base se disponível
        if ($knowledgeQualification) {
            $result['data_analise'] = $knowledgeQualification['data_analise'] ?? null;
            $result['tipo_analise'] = $knowledgeQualification['tipo_analise'] ?? 'generica';
            $result['criterios_usados'] = $knowledgeQualification['criterios_usados'] ?? null;
            $result['versao_analise'] = $knowledgeQualification['versao_analise'] ?? '1.0';
        }

        // Adiciona informações do histórico
        $conversationLog = $stream->conversation_log ?? [];
        if (is_string($conversationLog)) {
            $conversationLog = json_decode($conversationLog, true);
        }
        $history = $conversationLog['history'] ?? [];
        $result['total_messages'] = count($history);
        $result['last_qualification_count'] = $stream->last_qualification_count ?? 0;
        $result['last_qualification_analysis'] = $stream->last_qualification_analysis ?? null;

        return $result;
    }

    /**
     * Abre modal com detalhes da qualificação
     */
    public function showQualificationDetails($chatId)
    {
        try {
            $this->selectedChat = Chats::where('uuid', $chatId)
                ->where('user_id', $this->userId)
                ->with('agent')
                ->first();

            if ($this->selectedChat) {
                $this->showDetailsModal = true;
                $this->dispatch('openQualificationModal');
            } else {
                session()->flash('error', 'Chat não encontrado!');
            }
        } catch (\Exception $e) {
            session()->flash('error', 'Erro ao abrir detalhes: ' . $e->getMessage());
        }
    }

    /**
     * Fecha modal de detalhes
     */
    public function closeDetailsModal()
    {
        $this->showDetailsModal = false;
        $this->selectedChat = null;
    }

    /**
     * Força nova análise de qualificação
     */
    public function forceAnalysis($chatId)
    {
        // Proteção contra múltiplos cliques
        if ($this->isProcessing && $this->processingChatId === $chatId) {
            session()->flash('warning', 'Análise já está em processamento. Aguarde...');
            return;
        }

        // Verifica se há outro chat sendo processado
        if ($this->isProcessing && $this->processingChatId !== $chatId) {
            session()->flash('warning', 'Aguarde a conclusão da análise anterior.');
            return;
        }

        $chat = Chats::where('uuid', $chatId)
            ->where('user_id', $this->userId)
            ->first();

        if (!$chat) {
            session()->flash('error', 'Chat não encontrado!');
            return;
        }

        $agent = $chat->agent;
        if (!$agent) {
            session()->flash('error', 'Agente não encontrado para este chat!');
            return;
        }

        // Define estado de processamento
        $this->isProcessing = true;
        $this->processingChatId = $chatId;

        try {
            $leadQualificationService = $this->getLeadQualificationService();
            $result = $leadQualificationService->analyzeLead($chat, $agent, true);

            session()->flash('message', 'Análise de qualificação realizada com sucesso!');

            // Atualiza o chat selecionado se estiver no modal
            if ($this->selectedChat && $this->selectedChat->uuid === $chatId) {
                $this->selectedChat->refresh();
            }

            // Dispara evento para atualizar a view (Livewire 3)
            $this->dispatch('analysis-completed', chatId: $chatId);

            // Força atualização da página para mostrar novos dados
            $this->resetPage();
        } catch (\Exception $e) {
            session()->flash('error', 'Erro ao realizar análise: ' . $e->getMessage());
            Log::error('Erro ao forçar análise de qualificação', [
                'chat_id' => $chatId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        } finally {
            // Sempre limpa o estado de processamento
            $this->isProcessing = false;
            $this->processingChatId = null;
        }
    }

    /**
     * Reset de filtros
     */
    public function updatedFilterSearch()
    {
        $this->resetPage();
    }

    public function updatedFilterQualified()
    {
        $this->resetPage();
    }
}
