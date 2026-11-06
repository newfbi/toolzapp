<?php

namespace App\Http\Livewire\Crm;

use App\Http\Controllers\Knowledge\Services\LeadQualificationService;
use App\Models\Chats;
use App\Models\MemoryStream;
use App\Models\Agents;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Component;
use Livewire\WithPagination;

class CrmController extends Component
{
    use WithPagination;

    public $user;
    public $userId;
    public $filterSearch = '';
    public $filterQualified = '';
    public $filterStatus = '';
    public $filterAgent = '';
    public $filterDateFrom = '';
    public $filterDateTo = '';
    public $filterScoreMin = '';
    public $filterScoreMax = '';
    public $selectedChat = null;
    public $showDetailsModal = false;
    public $perPage = 15;
    public $processingChatId = null;
    public $isProcessing = false;
    public $leadStatuses = [
        'new' => 'Novo',
        'contacted' => 'Contatado',
        'qualified' => 'Qualificado',
        'proposal' => 'Proposta',
        'negotiation' => 'Negociação',
        'won' => 'Ganho',
        'lost' => 'Perdido',
        'follow_up' => 'Acompanhamento'
    ];
    protected $paginationTheme = 'bootstrap';

    public function mount()
    {
        $this->user = Auth::user();
        $this->userId = $this->user->uuid;
        view()->share('title', 'CRM - Gerenciamento de Leads');
    }

    public function render()
    {
        $chats = $this->getChatsQuery()->paginate($this->perPage);
        $stats = $this->getStatistics();
        $agents = Agents::where('user_id', $this->userId)->get();

        return view('livewire.crm.index', compact('chats', 'stats', 'agents'));
    }

    /**
     * Obtém instância do LeadQualificationService
     */
    private function getLeadQualificationService(): LeadQualificationService
    {
        return app(LeadQualificationService::class);
    }


    /**
     * Obtém query de chats com filtros avançados
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

        // Filtro por qualificação
        if (filled($this->filterQualified)) {
            $qualifiedChatIds = MemoryStream::whereHas('chat', function ($q) {
                $q->where('user_id', $this->userId);
            })
                ->select(['chat_id', 'lead_qualification'])
                ->whereNotNull('lead_qualification')
                ->get()
                ->filter(function ($stream) {
                    $qualification = $stream->lead_qualification;
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

        // Filtro por status do lead (usando campo conversion e outros)
        if (filled($this->filterStatus)) {
            switch ($this->filterStatus) {
                case 'won':
                    $query->where('conversion', true);
                    break;
                case 'lost':
                    $query->where('conversion', false);
                    break;
                case 'qualified':
                    // Leads qualificados mas não convertidos
                    $qualifiedChatIds = $this->getQualifiedChatIds();
                    $query->whereIn('uuid', $qualifiedChatIds)->where('conversion', false);
                    break;
                case 'new':
                    // Chats sem qualificação ou conversão
                    $qualifiedChatIds = $this->getQualifiedChatIds();
                    $query->whereNotIn('uuid', $qualifiedChatIds)->where('conversion', false);
                    break;
                case 'contacted':
                    // Chats com mensagens mas sem qualificação definida
                    $query->whereHas('messages')->where('conversion', false);
                    break;
            }
        }

        // Filtro por agente
        if (filled($this->filterAgent)) {
            $query->where('agent_id', $this->filterAgent);
        }

        // Filtros por score
        if (filled($this->filterScoreMin) || filled($this->filterScoreMax)) {
            $scoreChatIds = MemoryStream::whereHas('chat', function ($q) {
                $q->where('user_id', $this->userId);
            })
                ->select(['chat_id', 'lead_qualification'])
                ->whereNotNull('lead_qualification')
                ->get()
                ->filter(function ($stream) {
                    $qualification = $stream->lead_qualification;
                    if (is_string($qualification)) {
                        $qualification = json_decode($qualification, true);
                    }
                    if (!is_array($qualification)) {
                        return false;
                    }
                    $score = $qualification['score'] ?? 0;

                    $minCheck = !filled($this->filterScoreMin) || $score >= (int)$this->filterScoreMin;
                    $maxCheck = !filled($this->filterScoreMax) || $score <= (int)$this->filterScoreMax;

                    return $minCheck && $maxCheck;
                })
                ->pluck('chat_id')
                ->toArray();

            $query->whereIn('uuid', $scoreChatIds);
        }

        // Filtros por data
        if (filled($this->filterDateFrom)) {
            $query->whereDate('created_at', '>=', $this->filterDateFrom);
        }
        if (filled($this->filterDateTo)) {
            $query->whereDate('created_at', '<=', $this->filterDateTo);
        }

        return $query->orderBy('created_at', 'desc');
    }

    /**
     * Obtém IDs dos chats qualificados
     */
    private function getQualifiedChatIds(): array
    {
        return MemoryStream::whereHas('chat', function ($q) {
            $q->where('user_id', $this->userId);
        })
            ->select(['chat_id', 'lead_qualification'])
            ->whereNotNull('lead_qualification')
            ->get()
            ->filter(function ($stream) {
                $qualification = $stream->lead_qualification;
                if (is_string($qualification)) {
                    $qualification = json_decode($qualification, true);
                }
                return is_array($qualification) && ($qualification['qualified'] ?? false);
            })
            ->pluck('chat_id')
            ->toArray();
    }

    /**
     * Obtém estatísticas gerais do CRM
     */
    private function getStatistics(): array
    {
        $allChats = Chats::where('user_id', $this->userId)
            ->whereNotNull('phone')
            ->withCount('messages')
            ->get();

        // O painel era renderizado com uma consulta de memória e outra de
        // mensagens para cada chat. Em uma operação com centenas de leads
        // isso degradava o tempo de resposta e pressionava o banco.
        $streamsByChat = MemoryStream::whereIn('chat_id', $allChats->pluck('uuid'))
            ->latest('updated_at')
            ->get()
            ->groupBy('chat_id')
            ->map(static fn ($streams) => $streams->first());

        $totalLeads = $allChats->count();
        $qualifiedCount = 0;
        $notQualifiedCount = 0;
        $convertedCount = 0;
        $totalScore = 0;
        $scoreCount = 0;
        $newLeadsCount = 0;
        $contactedCount = 0;

        $qualifiedChatIds = $this->getQualifiedChatIds();

        foreach ($allChats as $chat) {
            $stream = $streamsByChat->get($chat->uuid);

            if ($stream && $stream->lead_qualification) {
                $qualification = $stream->lead_qualification;
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

            // Conta conversões
            if ($chat->conversion === true || $chat->conversion === 'true' || $chat->conversion === 1) {
                $convertedCount++;
            }

            // Conta leads novos (sem qualificação)
            if (!in_array($chat->uuid, $qualifiedChatIds) && !$chat->conversion) {
                $newLeadsCount++;
            }

            // Conta leads contactados (com mensagens)
            if ((int) $chat->messages_count > 0) {
                $contactedCount++;
            }
        }

        $averageScore = $scoreCount > 0 ? round($totalScore / $scoreCount, 1) : 0;
        $conversionRate = $totalLeads > 0 ? round(($convertedCount / $totalLeads) * 100, 1) : 0;
        $qualificationRate = $totalLeads > 0 ? round(($qualifiedCount / $totalLeads) * 100, 1) : 0;

        return [
            'total_leads' => $totalLeads,
            'qualified_count' => $qualifiedCount,
            'not_qualified_count' => $notQualifiedCount,
            'converted_count' => $convertedCount,
            'new_leads_count' => $newLeadsCount,
            'contacted_count' => $contactedCount,
            'average_score' => $averageScore,
            'conversion_rate' => $conversionRate,
            'qualification_rate' => $qualificationRate,
        ];
    }

    /**
     * Atualiza status do lead
     */
    public function updateLeadStatus($chatId, $status)
    {
        $chat = Chats::where('uuid', $chatId)
            ->where('user_id', $this->userId)
            ->first();

        if (!$chat) {
            session()->flash('error', 'Lead não encontrado!');
            return;
        }

        try {
            // Atualiza status baseado no tipo
            switch ($status) {
                case 'won':
                    $chat->update(['conversion' => 'true']);
                    break;
                case 'lost':
                    $chat->update(['conversion' => 'false']);
                    break;
                case 'qualified':
                case 'contacted':
                case 'proposal':
                case 'negotiation':
                case 'follow_up':
                case 'new':
                    // Para outros status, o sistema determina dinamicamente baseado na qualificação
                    // Não é necessário salvar no banco pois é calculado em tempo real
                    break;
            }

            session()->flash('message', 'Status do lead atualizado com sucesso!');
            $this->dispatch('lead-status-updated', chatId: $chatId, status: $status);
        } catch (\Exception $e) {
            session()->flash('error', 'Erro ao atualizar status: ' . $e->getMessage());
            Log::error('Erro ao atualizar status do lead', [
                'chat_id' => $chatId,
                'status' => $status,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Abre modal com detalhes do lead
     */
    public function showLeadDetails($chatId)
    {
        try {
            $this->selectedChat = Chats::where('uuid', $chatId)
                ->where('user_id', $this->userId)
                ->with('agent')
                ->first();

            if ($this->selectedChat) {
                $this->showDetailsModal = true;
                $this->dispatch('openLeadModal');
            } else {
                session()->flash('error', 'Lead não encontrado!');
            }
        } catch (\Exception $e) {
            session()->flash('error', 'Erro ao abrir detalhes: ' . $e->getMessage());
        }
    }


    /**
     * Fecha modais
     */
    public function closeModals()
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
            session()->flash('error', 'Lead não encontrado!');
            return;
        }

        $agent = $chat->agent;
        if (!$agent) {
            session()->flash('error', 'Agente não encontrado para este lead!');
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

    public function updatedFilterStatus()
    {
        $this->resetPage();
    }

    public function updatedFilterAgent()
    {
        $this->resetPage();
    }

    public function updatedFilterDateFrom()
    {
        $this->resetPage();
    }

    public function updatedFilterDateTo()
    {
        $this->resetPage();
    }

    public function updatedFilterScoreMin()
    {
        $this->resetPage();
    }

    public function updatedFilterScoreMax()
    {
        $this->resetPage();
    }

    /**
     * Limpa todos os filtros
     */
    public function clearFilters()
    {
        $this->filterSearch = '';
        $this->filterQualified = '';
        $this->filterStatus = '';
        $this->filterAgent = '';
        $this->filterDateFrom = '';
        $this->filterDateTo = '';
        $this->filterScoreMin = '';
        $this->filterScoreMax = '';
        $this->resetPage();
    }
}
