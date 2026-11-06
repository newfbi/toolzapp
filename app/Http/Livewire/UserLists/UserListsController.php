<?php

namespace App\Http\Livewire\UserLists;

use App\Helpers\PhoneHelper;
use App\Helpers\Utils;
use App\Models\UserList;
use App\Models\WhatsappDispatch;
use App\Models\WhatsappInstance;
use App\Models\WhatsappDispatchItem;
use App\Models\WhatsappLayout;
use App\Models\SmsDispatchItem;
use App\Models\SmsLayout;
use App\Jobs\ProcessSmsDispatch;
use App\Jobs\ProcessWhatsappDispatch;
use App\Services\SmsDispatchService;
use App\Services\WhatsappDispatchService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;
use Str;

class UserListsController extends Component
{
    use WithFileUploads;
    use WithPagination;

    protected $paginationTheme = 'bootstrap';

    /** Limite de contatos por importação */
    private const MAX_IMPORT_ROWS = 5000;

    // Filtros da listagem
    public string $search = '';
    public string $filterStatus = '';

    // Importação (CSV ou PDF)
    public $csvFile;
    public $importSummary = null;

    // Inclusão manual individual de Lead
    public string $newLeadName = '';
    public string $newLeadEmail = '';
    public string $newLeadPhone = '';
    public string $newLeadStatus = 'active';
    public bool $newLeadWhatsappOptIn = false;
    public bool $newLeadSmsOptIn = false;

    // Seleção e disparo
    /** @var array<string> UUIDs marcados nos checkboxes */
    public array $selected = [];
    public bool $selectAllFiltered = false;
    public bool $selectAllEntireList = false;
    public string $dispatchLayoutId = '';
    public string $dispatchSmsLayoutId = '';
    public string $dispatchAgentId = '';
    public string $dispatchChannel = 'whatsapp';
    public bool $dispatchConfirmOptIn = false;
    public bool $dispatchIncludeAlreadySent = false;

    // Detalhes de um disparo (modal de gestão)
    public ?array $viewingDispatch = null;
    /** @var array<int, array> */
    public array $viewingDispatchItems = [];

    public function mount()
    {
        view()->share('title', 'Lista de Usuários');
    }

    public function updatingSearch() { $this->resetPage(); }
    public function updatingFilterStatus() { $this->resetPage(); }

    public function render()
    {
        $userId = Auth::user()->uuid;

        // A Z-API pode alterar o estado fora do painel. Sincronizar somente
        // essas instâncias mantém o seletor de disparo fiel sem fazer o
        // carregamento da página depender do bridge Baileys.
        $providerService = app(\App\Services\WhatsappProviderService::class);
        WhatsappInstance::where('user_id', $userId)
            ->where('provider', 'zapi')
            ->whereIn('status', ['connected', 'disconnected', 'error'])
            ->get()
            ->each(fn ($instance) => $providerService->isConnected($instance));

        $query = UserList::forUser($userId)->orderBy('created_at', 'desc');

        if ($this->search) {
            $term = '%' . $this->search . '%';
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', $term)
                    ->orWhere('email', 'like', $term)
                    ->orWhere('phone', 'like', $term);
            });
        }

        if ($this->filterStatus) {
            $query->where('status', $this->filterStatus);
        }

        return view('livewire.user-lists.index', [
            'lists' => $query->paginate(20),
            'totalContacts' => UserList::forUser($userId)->count(),
            'activeContacts' => UserList::forUser($userId)->where('status', 'active')->count(),
            'inactiveContacts' => UserList::forUser($userId)->where('status', 'inactive')->count(),
            'totalSentMessages' => WhatsappDispatchItem::where('status', 'sent')
                ->whereHas('dispatch', fn ($dispatchQuery) => $dispatchQuery->where('user_id', $userId))
                ->count() + SmsDispatchItem::whereIn('status', ['accepted', 'delivered'])
                ->whereHas('dispatch', fn ($dispatchQuery) => $dispatchQuery->where('user_id', $userId))
                ->count(),
            'dispatchLayouts' => WhatsappLayout::forUser($userId)->where('status', 'active')->orderBy('name')->get(),
            'smsLayouts' => SmsLayout::forUser($userId)->where('status', 'active')->orderBy('name')->get(),
            'smsProviderLabel' => app(\App\Services\Sms\SmsProviderManager::class)
                ->available()[app(\App\Services\Sms\SmsProviderManager::class)->activeKey()]['label']
                ?? 'Provedor SMS',
            'connectedInstances' => WhatsappInstance::where('user_id', $userId)
                ->where('status', 'connected')->with('agent')->get(),
            'receivedCounts' => $this->receivedCountsForPage(),
        ]);
    }

    /**
     * Quantas mensagens cada contato da página atual já recebeu
     * (para o badge de gestão na listagem)
     */
    private function receivedCountsForPage(): array
    {
        $pageIds = $this->filteredContactsQuery()
            ->orderBy('created_at', 'desc')
            ->forPage($this->getPage(), 20)
            ->pluck('uuid')
            ->all();

        if (empty($pageIds)) {
            return [];
        }

        return WhatsappDispatchItem::whereIn('user_list_id', $pageIds)
            ->where('status', 'sent')
            ->selectRaw('user_list_id, COUNT(*) as total, MAX(sent_at) as last_sent_at')
            ->groupBy('user_list_id')
            ->get()
            ->mapWithKeys(fn ($row) => [
                $row->user_list_id => [
                    'total' => (int) $row->total,
                    'last_sent_at' => $row->last_sent_at,
                ],
            ])
            ->all();
    }

    /* ============================================================
     * IMPORTAÇÃO (CSV / PDF)
     * ============================================================ */

    /**
     * Importa contatos de um arquivo CSV ou PDF para a lista do usuário.
     */
    public function importFile()
    {
        try {
            $this->validate([
                'csvFile' => 'required|file|mimes:csv,txt,pdf|max:5120',
            ], [
                'csvFile.required' => 'Selecione um arquivo para importar.',
                'csvFile.mimes' => 'Formato inválido. Envie um arquivo CSV ou PDF.',
                'csvFile.max' => 'O arquivo deve ter no máximo 5 MB.',
            ]);

            if (strtolower($this->csvFile->getClientOriginalExtension()) === 'pdf') {
                $rawRows = $this->readPdfRows();
            } else {
                $rawRows = $this->readCsvRows();
            }

            if (empty($rawRows)) {
                throw new \RuntimeException('Nenhum contato foi encontrado no arquivo.');
            }

            $this->processRows($rawRows);
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages(['csvFile' => $e->getMessage()]);
        } catch (\Exception $e) {
            Log::error('Erro ao importar lista de usuários', [
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);
            session()->flash('error', 'Erro ao processar o arquivo: ' . $e->getMessage());
        } finally {
            $this->reset('csvFile');
        }
    }

    private function readCsvRows(): array
    {
        $handle = fopen($this->csvFile->getRealPath(), 'r');
        if (!$handle) {
            throw new \RuntimeException('Não foi possível abrir o arquivo enviado.');
        }

        try {
            $headerLine = fgets($handle);
            if ($headerLine === false) {
                throw new \RuntimeException('O arquivo está vazio.');
            }
            $delimiter = substr_count($headerLine, ';') >= substr_count($headerLine, ',') ? ';' : ',';
            $header = str_getcsv(trim($headerLine), $delimiter);

            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
            $map = [];
            foreach ($header as $index => $rawColumn) {
                $column = $this->normalizeHeader($rawColumn);
                $field = match (true) {
                    in_array($column, ['name', 'nome', 'nomecompleto', 'fullname']) => 'name',
                    in_array($column, ['email', 'e-mail', 'mail', 'correio']) => 'email',
                    in_array($column, ['phone', 'telefone', 'celular', 'whatsapp', 'fone', 'mobile']) => 'phone',
                    in_array($column, ['status', 'situacao']) => 'status',
                    in_array($column, ['optin', 'whatsappoptin', 'consentimento', 'autorizacaowhatsapp', 'autorizadomensagens']) => 'whatsapp_opt_in',
                    in_array($column, ['smsoptin', 'autorizacaosms', 'consentimentosms']) => 'sms_opt_in',
                    default => null,
                };
                if ($field && !isset($map[$field])) {
                    $map[$field] = $index;
                }
            }

            if (!isset($map['name'])) {
                throw new \RuntimeException(
                    'Cabeçalho inválido. O arquivo deve conter as colunas: nome/name, email/e-mail, telefone/phone e status/situacao.'
                );
            }

            $rawRows = [];
            while (($line = fgets($handle)) !== false) {
                if (trim($line) === '') {
                    continue;
                }
                $cells = str_getcsv($line, $delimiter);
                $rawRows[] = [
                    'name' => trim($cells[$map['name']] ?? ''),
                    'email' => isset($map['email']) ? strtolower(trim($cells[$map['email']] ?? '')) : '',
                    'phone' => isset($map['phone']) ? trim($cells[$map['phone']] ?? '') : '',
                    'status' => isset($map['status']) ? $this->normalizeStatus($cells[$map['status']] ?? '') : 'active',
                    'whatsapp_opt_in' => isset($map['whatsapp_opt_in'])
                        ? $this->normalizeOptIn($cells[$map['whatsapp_opt_in']] ?? '')
                        : false,
                    'sms_opt_in' => isset($map['sms_opt_in'])
                        ? $this->normalizeOptIn($cells[$map['sms_opt_in']] ?? '')
                        : false,
                ];
            }

            return $rawRows;
        } finally {
            fclose($handle);
        }
    }

    private function readPdfRows(): array
    {
        try {
            $text = Utils::extractTextFromPdf($this->csvFile->getRealPath());
        } catch (\Throwable $e) {
            throw new \RuntimeException('Não foi possível ler o PDF: ' . $e->getMessage());
        }

        if (trim($text) === '') {
            throw new \RuntimeException(
                'O PDF não possui texto extraível. Provavelmente é uma imagem digitalizada — use um arquivo CSV.'
            );
        }

        $rawRows = [];
        foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
            if ($contact = $this->parseContactLine($line)) {
                $rawRows[] = $contact;
            }
        }

        return $rawRows;
    }

    /**
     * Extrai um contato de uma linha de texto livre do PDF.
     */
    private function parseContactLine(string $line): ?array
    {
        $line = trim((string) preg_replace('/[ \t]+/', ' ', $line));
        if ($line === '' || mb_strlen($line) < 5) {
            return null;
        }

        $email = '';
        $mEmail = null;
        if (preg_match('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', $line, $mEmail)) {
            $email = strtolower($mEmail[0]);
        }

        // Testa todos os candidatos e usa o primeiro que passar na normalização
        $phone = '';
        $matchedPhoneText = '';
        if (preg_match_all('/(?<!\d)(?:\+?\s?55[\s\-.]?)?\(?\d{2}\)?[\s\-.]?\d{4,5}[\s\-.]?\d{4}(?!\d)/', $line, $allPhones)) {
            foreach ($allPhones[0] as $candidate) {
                $result = PhoneHelper::normalize($candidate);
                if ($result['phone'] !== null) {
                    $phone = $result['phone'];
                    $matchedPhoneText = $candidate;
                    break;
                }
            }
        }

        if ($email === '' && $phone === '') {
            return null;
        }

        $status = preg_match('/\b(inativ[oa]s?|inactive)\b/i', $line) ? 'inactive' : 'active';

        $name = str_replace($mEmail[0] ?? "\x00", '', $line);
        if ($matchedPhoneText !== '') {
            $name = str_replace($matchedPhoneText, '', $name);
        }
        $name = preg_replace('/(?:\d[\s\-.]?){7,}\d?/', ' ', (string) $name);
        $name = preg_replace('/\b(inativ[oa]s?|inactive|ativ[oa]s?|active)\b/i', '', (string) $name);
        $name = preg_replace('/^\s*\d+[\.\)]\s*/', '', (string) $name);
        $name = preg_replace('/\s{2,}/', ' ', (string) $name);
        $name = trim((string) preg_replace('/^[\s\-–—:;,|.]+|[\s\-–—:;,|.]+$/u', '', (string) $name));

        if ($name === '') {
            $name = $email !== '' ? $email : $phone;
        }

        return ['name' => $name, 'email' => $email, 'phone' => $phone, 'status' => $status];
    }

    /**
     * Pipeline compartilhado: valida, deduplica e insere em lote.
     */
    private function processRows(array $rawRows): void
    {
        $errors = [];
        if (count($rawRows) > self::MAX_IMPORT_ROWS) {
            $rawRows = array_slice($rawRows, 0, self::MAX_IMPORT_ROWS);
            $errors[] = 'Importação limitada a ' . self::MAX_IMPORT_ROWS . ' contatos por vez. Envie o restante em outro arquivo.';
        }

        $userId = Auth::user()->uuid;

        $existingContacts = UserList::forUser($userId)
            ->select(['uuid', 'email', 'phone', 'whatsapp_opt_in', 'sms_opt_in'])
            ->get();
        $existingByEmail = $existingContacts
            ->filter(fn ($contact) => !empty($contact->email))
            ->keyBy(fn ($contact) => strtolower((string) $contact->email));
        $existingByPhone = $existingContacts
            ->mapWithKeys(function ($contact) {
                $phone = PhoneHelper::normalize((string) $contact->phone)['phone'];

                return $phone ? [$phone => $contact] : [];
            });

        $existingEmails = $existingByEmail->keys()->all();
        $existingPhones = array_values(array_filter(array_map(
            fn ($rawPhone) => PhoneHelper::normalize((string) $rawPhone)['phone'],
            $existingContacts->pluck('phone')->all()
        )));

        $seenEmails = [];
        $seenPhones = [];

        $rows = [];
        $imported = 0;
        $skipped = 0;
        $invalid = 0;
        $warned = 0;
        $withoutOptIn = 0;
        $optInUpdated = 0;
        $withoutSmsOptIn = 0;
        $smsOptInUpdated = 0;

        foreach ($rawRows as $row) {
            if ($row['name'] === '') {
                if ($row['email'] !== '') {
                    $row['name'] = $row['email'];
                } else {
                    $invalid++;
                    continue;
                }
            }
            if ($row['email'] === '' && $row['phone'] === '') {
                $invalid++;
                continue;
            }

            if ($row['email'] !== '' && !filter_var($row['email'], FILTER_VALIDATE_EMAIL)) {
                $errors[] = "E-mail inválido em \"{$row['name']}\": {$row['email']} (contato importado sem e-mail)";
                $row['email'] = '';
                $warned++;
            }

            // Telefone: normaliza/valida pela função central
            $row['phone'] = trim((string) ($row['phone'] ?? ''));
            if ($row['phone'] !== '') {
                $normalized = PhoneHelper::normalize($row['phone']);
                if ($normalized['phone'] !== null) {
                    $row['phone'] = $normalized['phone'];
                } else {
                    $reason = $normalized['reason'] ?? 'formato inválido';
                    $errors[] = "Telefone inválido em \"{$row['name']}\": {$row['phone']} ({$reason}) — contato importado sem telefone";
                    $row['phone'] = '';
                    $warned++;
                }
            }

            if ($row['email'] === '' && $row['phone'] === '') {
                $invalid++;
                continue;
            }

            $dupeKeyEmail = $row['email'] !== '' ? $row['email'] : null;
            $dupeKeyPhone = $row['phone'] !== '' ? $row['phone'] : null;

            $existingDuplicate = ($dupeKeyEmail ? $existingByEmail->get($dupeKeyEmail) : null)
                ?: ($dupeKeyPhone ? $existingByPhone->get($dupeKeyPhone) : null);
            $isDuplicate = ($dupeKeyEmail && (isset($seenEmails[$dupeKeyEmail]) || in_array($dupeKeyEmail, $existingEmails)))
                || ($dupeKeyPhone && (isset($seenPhones[$dupeKeyPhone]) || in_array($dupeKeyPhone, $existingPhones)));

            if ($isDuplicate) {
                if ($existingDuplicate && ($row['whatsapp_opt_in'] ?? false) && !$existingDuplicate->whatsapp_opt_in) {
                    $existingDuplicate->update(['whatsapp_opt_in' => true]);
                    $optInUpdated++;
                }
                if ($existingDuplicate && ($row['sms_opt_in'] ?? false) && !$existingDuplicate->sms_opt_in) {
                    $existingDuplicate->update(['sms_opt_in' => true]);
                    $smsOptInUpdated++;
                }
                $skipped++;
                continue;
            }

            if ($dupeKeyEmail) {
                $seenEmails[$dupeKeyEmail] = true;
            }
            if ($dupeKeyPhone) {
                $seenPhones[$dupeKeyPhone] = true;
            }

            $now = now();
            $rows[] = [
                'uuid' => Str::limit(Str::uuid(), 8, ''),
                'user_id' => $userId,
                'name' => mb_substr($row['name'], 0, 255),
                'email' => $row['email'] ?: null,
                'phone' => $row['phone'] ?: null,
                'status' => $row['status'],
                'whatsapp_opt_in' => (bool) ($row['whatsapp_opt_in'] ?? false),
                'sms_opt_in' => (bool) ($row['sms_opt_in'] ?? false),
                'created_at' => $now,
                'updated_at' => $now,
            ];
            if (!($row['whatsapp_opt_in'] ?? false)) {
                $withoutOptIn++;
            }
            if (!($row['sms_opt_in'] ?? false)) {
                $withoutSmsOptIn++;
            }
            $imported++;

            if (count($rows) >= 200) {
                UserList::insert($rows);
                $rows = [];
            }
        }

        if (!empty($rows)) {
            UserList::insert($rows);
        }

        $this->importSummary = [
            'imported' => $imported,
            'skipped' => $skipped,
            'invalid' => $invalid,
            'warned' => $warned,
            'without_opt_in' => $withoutOptIn,
            'opt_in_updated' => $optInUpdated,
            'without_sms_opt_in' => $withoutSmsOptIn,
            'sms_opt_in_updated' => $smsOptInUpdated,
            'errors' => array_slice(array_unique($errors), 0, 5),
        ];

        Log::info('Importação de lista de usuários concluída', [
            'user_id' => $userId,
            'imported' => $imported,
            'skipped' => $skipped,
            'invalid' => $invalid,
            'warned' => $warned,
            'opt_in_updated' => $optInUpdated,
            'sms_opt_in_updated' => $smsOptInUpdated,
        ]);
    }

    /* ============================================================
     * SELEÇÃO E DISPARO
     * ============================================================ */

    /**
     * Quantidade de contatos no escopo atual do modal:
     * selecionados > todos os filtrados
     */
    public function getDispatchScopeCountProperty(): int
    {
        if (count($this->selected) > 0) {
            return count($this->selected);
        }

        if ($this->selectAllEntireList) {
            return UserList::forUser(Auth::user()->uuid)->count();
        }

        if ($this->selectAllFiltered) {
            return $this->filteredContactsQuery()->count();
        }

        return 0;
    }

    private function filteredContactsQuery()
    {
        $userId = Auth::user()->uuid;
        $query = UserList::forUser($userId);

        if ($this->search) {
            $term = '%' . $this->search . '%';
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', $term)
                    ->orWhere('email', 'like', $term)
                    ->orWhere('phone', 'like', $term);
            });
        }
        if ($this->filterStatus) {
            $query->where('status', $this->filterStatus);
        }

        return $query;
    }

    /** Seleciona/deseleciona todos os contatos da página atual */
    public function toggleSelectPage(): void
    {
        $pageUuids = $this->filteredContactsQuery()
            ->orderBy('created_at', 'desc')
            ->forPage($this->getPage(), 20)
            ->pluck('uuid')
            ->all();

        $allSelected = count(array_intersect($pageUuids, $this->selected)) === count($pageUuids)
            && count($pageUuids) > 0;

        if ($allSelected) {
            $this->selected = array_values(array_diff($this->selected, $pageUuids));
            $this->selectAllFiltered = false;
            $this->selectAllEntireList = false;
        } else {
            $this->selected = array_values(array_unique(array_merge($this->selected, $pageUuids)));
            $this->selectAllFiltered = false;
            $this->selectAllEntireList = false;
        }
    }

    /** Marca todos os contatos da lista, ignorando paginação e filtros ativos. */
    public function selectAllContacts(): void
    {
        $this->selected = [];
        $this->selectAllFiltered = false;
        $this->selectAllEntireList = true;
    }

    /** Marca TODOS os contatos que casam com o filtro atual (sem limite de página) */
    public function selectAllFiltered(): void
    {
        $this->selected = [];
        $this->selectAllFiltered = true;
        $this->selectAllEntireList = false;
    }

    public function clearSelection(): void
    {
        $this->selected = [];
        $this->selectAllFiltered = false;
        $this->selectAllEntireList = false;
        $this->reset(['dispatchLayoutId', 'dispatchSmsLayoutId', 'dispatchAgentId', 'dispatchConfirmOptIn', 'dispatchIncludeAlreadySent']);
        $this->dispatchChannel = 'whatsapp';
    }

    /** Ação individual na linha: pré-seleciona o contato e abre o modal */
    #[On('openSingleDispatch')]
    public function sendSingle(string $contactId): void
    {
        $exists = UserList::forUser(Auth::user()->uuid)->where('uuid', $contactId)->exists();
        if (!$exists) {
            session()->flash('error', 'Contato não encontrado.');
            return;
        }

        $this->selected = [$contactId];
        $this->selectAllFiltered = false;
        $this->selectAllEntireList = false;
        $this->openDispatchModal();
    }

    public function updatedSelected(): void
    {
        // Marcou algo manualmente? Cancela o modo "todos filtrados"
        if (count($this->selected) > 0) {
            $this->selectAllFiltered = false;
            $this->selectAllEntireList = false;
        }
    }

    public function updatedDispatchChannel(string $channel): void
    {
        if ($channel === 'sms') {
            $this->dispatchAgentId = '';
            $this->dispatchLayoutId = '';
        } else {
            $this->dispatchSmsLayoutId = '';
        }
        $this->resetErrorBag();
    }

    public function openDispatchModal(): void
    {
        if ($this->getDispatchScopeCountProperty() === 0) {
            session()->flash('error', 'Selecione contatos ou aplique um filtro antes de disparar.');
            return;
        }

        if ($this->dispatchChannel === 'whatsapp') {
            // O Baileys pode estar conectado enquanto o último evento transitório
            // ainda deixou o status persistido como "disconnected". Sincroniza
            // antes de montar o select para não esconder uma instância disponível.
            $this->syncDispatchInstanceStatuses();
            $connected = $this->connectedInstancesForDispatch();
            if ($connected->count() === 1 && empty($this->dispatchAgentId)) {
                $this->dispatchAgentId = $connected->first()->agent_id;
            }
        }

        $this->resetErrorBag();
        $this->dispatch('openDispatchModalEvent');
    }

    public function closeDispatchModal(): void
    {
        $this->reset(['dispatchLayoutId', 'dispatchSmsLayoutId', 'dispatchAgentId', 'dispatchConfirmOptIn', 'dispatchIncludeAlreadySent']);
        $this->dispatchChannel = 'whatsapp';
        $this->resetErrorBag();
        $this->dispatch('closeDispatchModalEvent');
    }

    /**
     * Quantos contatos do escopo atual JÁ receberam o layout escolhido
     * (atualiza ao trocar o select do layout no modal)
     */
    public function getAlreadyReceivedCountProperty(): int
    {
        $layoutId = $this->dispatchChannel === 'sms' ? $this->dispatchSmsLayoutId : $this->dispatchLayoutId;
        if (!$layoutId) {
            return 0;
        }

        if ($this->dispatchChannel === 'sms') {
            return SmsDispatchItem::where('layout_id', $layoutId)
                ->whereIn('status', ['accepted', 'delivered'])
                ->whereIn('user_list_id', $this->resolveDispatchContactUuids())
                ->distinct()
                ->count('user_list_id');
        }

        return WhatsappDispatchItem::where('layout_id', $layoutId)
            ->where('status', 'sent')
            ->whereIn('user_list_id', $this->resolveDispatchContactUuids())
            ->distinct()
            ->count('user_list_id');
    }

    /**
     * Resolve os UUIDs do escopo: selecionados > todos os filtrados
     */
    private function resolveDispatchContactUuids(): array
    {
        if (count($this->selected) > 0) {
            return $this->selected;
        }

        if ($this->selectAllEntireList) {
            return UserList::forUser(Auth::user()->uuid)->pluck('uuid')->all();
        }

        if ($this->selectAllFiltered) {
            return $this->filteredContactsQuery()->pluck('uuid')->all();
        }

        return [];
    }

    /**
     * Confirma e agenda o disparo: valida, cria a fila com pacing anti-spam,
     * pula quem já recebeu o layout (salvo reenvio explícito) e enfileira.
     */
    public function startDispatch(WhatsappDispatchService $service): void
    {
        $scopeCount = $this->getDispatchScopeCountProperty();
        if ($scopeCount === 0) {
            session()->flash('error', 'Nenhum contato no escopo do disparo.');
            return;
        }

        $this->validate([
            'dispatchChannel' => 'required|in:whatsapp,sms',
            'dispatchConfirmOptIn' => 'accepted',
        ], [
            'dispatchChannel.in' => 'Escolha um canal válido.',
            'dispatchConfirmOptIn.accepted' => 'Confirme que os contatos autorizaram receber mensagens.',
        ]);

        $contactUuids = $this->resolveDispatchContactUuids();

        if ($this->dispatchChannel === 'sms') {
            $this->validate([
                'dispatchSmsLayoutId' => 'required|string',
            ], [
                'dispatchSmsLayoutId.required' => 'Selecione o tema SMS.',
            ]);

            try {
                $smsResult = app(SmsDispatchService::class)->createDispatch(
                    Auth::user()->uuid,
                    $this->dispatchSmsLayoutId,
                    $contactUuids,
                    $this->dispatchIncludeAlreadySent
                );

                ProcessSmsDispatch::dispatch($smsResult['dispatch']->uuid);
                $eta = $smsResult['eta']->setTimezone('America/Sao_Paulo')->format('d/m H:i');
                $notes = [];
                if ($smsResult['invalid_phones'] > 0) $notes[] = "{$smsResult['invalid_phones']} sem telefone válido";
                if ($smsResult['invalid_messages'] > 0) $notes[] = "{$smsResult['invalid_messages']} mensagem(ns) acima do limite de partes SMS";
                if ($smsResult['already_received'] > 0 && !$this->dispatchIncludeAlreadySent) $notes[] = "{$smsResult['already_received']} já receberam este tema";
                if ($smsResult['without_opt_in'] > 0) $notes[] = "{$smsResult['without_opt_in']} sem opt-in SMS";
                $noteText = $notes ? ' (' . implode('; ', $notes) . ')' : '';

                session()->flash('message', "Fila SMS criada: {$smsResult['dispatch']->total_items} mensagem(ns) em lotes{$noteText}. Previsão inicial: {$eta}.");
                $this->clearSelection();
                $this->dispatch('closeDispatchModalEvent');
                return;
            } catch (\InvalidArgumentException $e) {
                throw ValidationException::withMessages(['dispatchSmsLayoutId' => $e->getMessage()]);
            } catch (\Exception $e) {
                Log::error('Erro ao iniciar disparo SMS', ['user_id' => Auth::id(), 'error' => $e->getMessage()]);
                session()->flash('error', 'Erro ao criar a fila SMS: ' . $e->getMessage());
                return;
            }
        }

        // Revalida a conexão real antes de aceitar o disparo WhatsApp. Isso
        // mantém o fluxo existente independente da nova fila SMS.
        $this->syncDispatchInstanceStatuses($service);
        if (count($this->connectedInstancesForDispatch()) === 0) {
            session()->flash('error', 'Nenhuma instância WhatsApp conectada. Conecte uma instância em WhatsApp > Instâncias.');
            return;
        }

        $this->validate([
            'dispatchLayoutId' => 'required|string',
            'dispatchAgentId' => 'required|string',
            'dispatchConfirmOptIn' => 'accepted',
        ], [
            'dispatchLayoutId.required' => 'Selecione a mensagem pronta (layout).',
            'dispatchAgentId.required' => 'Selecione a instância de envio.',
            'dispatchConfirmOptIn.accepted' => 'Confirme que os contatos autorizam receber mensagens.',
        ]);

        try {
            $result = $service->createDispatch(
                Auth::user()->uuid,
                $this->dispatchLayoutId,
                $this->dispatchAgentId,
                $contactUuids,
                $this->dispatchIncludeAlreadySent
            );

            ProcessWhatsappDispatch::dispatch($result['dispatch']->uuid);

            $eta = $result['eta']->setTimezone('America/Sao_Paulo')->format('d/m H:i');
            $notes = [];
            if ($result['invalid_phones'] > 0) {
                $notes[] = "{$result['invalid_phones']} sem telefone válido pulado(s)";
            }
            if ($result['already_received'] > 0 && !$this->dispatchIncludeAlreadySent) {
                $notes[] = "{$result['already_received']} já recebido(s) este layout e ignorado(s)";
            }
            if ($result['without_opt_in'] > 0) {
                $notes[] = "{$result['without_opt_in']} sem opt-in foram bloqueado(s)";
            }

            $noteText = count($notes) ? ' (' . implode('; ', $notes) . ')' : '';

            session()->flash('message',
                "Fila criada: {$result['dispatch']->total_items} mensagem(ns) em envio escalonado{$noteText}. " .
                "Previsão de término: {$eta}. Você pode fechar esta página — o envio continua no servidor."
            );

            $this->clearSelection();
            $this->reset(['dispatchLayoutId', 'dispatchSmsLayoutId', 'dispatchAgentId', 'dispatchConfirmOptIn', 'dispatchIncludeAlreadySent']);
            $this->dispatchChannel = 'whatsapp';
            $this->dispatch('closeDispatchModalEvent');
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['dispatchLayoutId' => $e->getMessage()]);
        } catch (\Exception $e) {
            Log::error('Erro ao iniciar disparo', [
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);
            session()->flash('error', 'Erro ao criar a fila de disparo: ' . $e->getMessage());
        }
    }

    /**
     * Abre o modal com o detalhe de um disparo: cada contato, status,
     * horário do envio e erros.
     */
    public function dispatchDetails(string $dispatchUuid): void
    {
        $dispatch = WhatsappDispatch::where('user_id', Auth::user()->uuid)
            ->where('uuid', $dispatchUuid)
            ->first();

        if (!$dispatch) {
            session()->flash('error', 'Disparo não encontrado.');
            return;
        }

        $this->viewingDispatch = [
            'uuid' => $dispatch->uuid,
            'name' => $dispatch->layout?->name ?? '(layout removido)',
            'status' => $dispatch->status,
            'total' => $dispatch->total_items,
            'sent' => $dispatch->sent_count,
            'delivered' => $dispatch->items()->whereIn('delivery_status', ['delivered', 'read', 'played'])->count(),
            'read' => $dispatch->items()->whereIn('delivery_status', ['read', 'played'])->count(),
            'failed' => $dispatch->failed_count,
            'skipped' => $dispatch->skipped_count,
            'created_at' => $dispatch->created_at->format('d/m/Y H:i'),
        ];

        $this->viewingDispatchItems = $dispatch->items()
            ->with('contact')
            ->orderByRaw("FIELD(status, 'sent', 'pending', 'failed', 'skipped')")
            ->orderBy('sent_at')
            ->get()
            ->map(fn ($item) => [
                'name' => $item->contact?->name ?? '(contato removido)',
                'phone' => $item->phone ?? '--',
                'status' => $item->status,
                'delivery_status' => $item->delivery_status ?? 'unknown',
                'sent_at' => $item->sent_at?->format('d/m H:i'),
                'error' => $item->error_message,
            ])
            ->all();

        $this->dispatch('openDispatchDetailsEvent');
    }

    public function closeDispatchDetails(): void
    {
        $this->reset(['viewingDispatch', 'viewingDispatchItems']);
    }

    public function cancelDispatch(string $dispatchUuid): void
    {
        app(WhatsappDispatchService::class)->cancelDispatch(Auth::user()->uuid, $dispatchUuid);
        session()->flash('message', 'Disparo cancelado. Itens pendentes não serão enviados.');
    }

    /** Instâncias conectadas para o select do modal */
    public function connectedInstancesForDispatch()
    {
        $userId = Auth::user()->uuid;
        $providerService = app(\App\Services\WhatsappProviderService::class);
        WhatsappInstance::where('user_id', $userId)
            ->where('provider', 'zapi')
            ->get()
            ->each(fn ($instance) => $providerService->isConnected($instance));

        return WhatsappInstance::where('user_id', $userId)
            ->where('status', 'connected')
            ->with('agent')
            ->get();
    }

    /**
     * Sincroniza o status persistido com o estado real informado pelo bridge.
     * Eventos transitórios do Baileys podem atualizar o banco depois da
     * conexão já ter sido restabelecida, deixando a instância fora do select.
     */
    private function syncDispatchInstanceStatuses(?WhatsappDispatchService $service = null): void
    {
        $service ??= app(WhatsappDispatchService::class);

        $instances = WhatsappInstance::where('user_id', Auth::user()->uuid)
            ->whereIn('status', ['connected', 'connecting', 'disconnected'])
            ->get();

        foreach ($instances as $instance) {
            if (!$service->isInstanceConnected((string) $instance->agent_id)) {
                continue;
            }

            if ($instance->status !== 'connected' || $instance->error_message !== null) {
                $instance->update([
                    'status' => 'connected',
                    'error_message' => null,
                    'connected_at' => $instance->connected_at ?: now(),
                    'last_activity' => now(),
                ]);
            }
        }
    }

    /* ============================================================
     * HELPERS E AÇÕES DE CONTATO
     * ============================================================ */

    private function normalizeHeader(string $value): string
    {
        $value = mb_strtolower(trim($value));

        return preg_replace('/[\s\-_]+/', '', (string) iconv('UTF-8', 'ASCII//TRANSLIT', $value));
    }

    private function normalizeStatus(string $value): string
    {
        $normalized = $this->normalizeHeader($value);

        return in_array($normalized, ['inativo', 'inactive', 'inativos', 'off', '0'])
            ? 'inactive'
            : 'active';
    }

    private function normalizeOptIn(string $value): bool
    {
        $normalized = $this->normalizeHeader($value);

        return in_array($normalized, [
            '1', 'true', 'sim', 's', 'yes', 'y', 'ok', 'autorizado', 'autorizada',
            'consentiu', 'consentimento', 'optin', 'permitido', 'permitida',
        ], true);
    }

    private function fetchExistingKeys($query, string $column): array
    {
        $keys = [];
        $query->pluck($column)->chunk(1000)->each(function ($chunk) use (&$keys) {
            foreach ($chunk as $key) {
                $keys[] = $key;
            }
        });

        return $keys;
    }

    public function toggleStatus(string $contactId)
    {
        $contact = UserList::forUser(Auth::user()->uuid)->where('uuid', $contactId)->first();
        if (!$contact) {
            session()->flash('error', 'Contato não encontrado.');
            return;
        }

        $contact->update(['status' => $contact->status === 'active' ? 'inactive' : 'active']);
        session()->flash('message', 'Status atualizado com sucesso!');
    }

    public function showDeleteConfirm(string $contactId, string $contactName)
    {
        $this->dispatch('showDeleteContactConfirm', [
            'id' => $contactId,
            'name' => $contactName,
        ]);
    }

    #[On('deleteContact')]
    public function deleteContact(string $contactId)
    {
        $deleted = UserList::forUser(Auth::user()->uuid)->where('uuid', $contactId)->delete();
        if (!$deleted) {
            $this->dispatch('error', 'Contato não encontrado.');
            return;
        }

        // Remove da seleção, se marcado
        $this->selected = array_values(array_diff($this->selected, [$contactId]));

        $this->dispatch('contactDeleted', 'Contato excluído com sucesso!');
    }

    public function openCreateLeadModal(): void
    {
        $this->reset(['newLeadName', 'newLeadEmail', 'newLeadPhone']);
        $this->newLeadStatus = 'active';
        $this->newLeadWhatsappOptIn = false;
        $this->newLeadSmsOptIn = false;
        $this->resetValidation();
        $this->dispatch('openCreateLeadModalEvent');
    }

    public function closeCreateLeadModal(): void
    {
        $this->reset(['newLeadName', 'newLeadEmail', 'newLeadPhone']);
        $this->newLeadStatus = 'active';
        $this->newLeadWhatsappOptIn = false;
        $this->newLeadSmsOptIn = false;
        $this->resetValidation();
        $this->dispatch('closeCreateLeadModalEvent');
    }

    public function saveIndividualLead(): void
    {
        $this->validate([
            'newLeadName' => 'required|string|min:2|max:255',
            'newLeadEmail' => 'nullable|email|max:255',
            'newLeadPhone' => 'required|string|min:8|max:30',
            'newLeadStatus' => 'required|in:active,inactive',
        ], [
            'newLeadName.required' => 'Informe o nome do lead.',
            'newLeadName.min' => 'O nome deve ter pelo menos 2 caracteres.',
            'newLeadEmail.email' => 'Informe um e-mail válido.',
            'newLeadPhone.required' => 'Informe o telefone do lead com DDD.',
        ]);

        $norm = PhoneHelper::normalize($this->newLeadPhone);
        if (!$norm['phone']) {
            $reason = $norm['reason'] ? " ({$norm['reason']})" : '';
            $this->addError('newLeadPhone', "Telefone inválido{$reason}. Informe o número completo com DDD.");
            return;
        }

        $userId = Auth::user()->uuid;
        $cleanPhone = $norm['phone'];

        // Verificar duplicados para este usuário
        $exists = UserList::where('user_id', $userId)
            ->where(function ($q) use ($cleanPhone) {
                $q->where('phone', $cleanPhone);
                if (!empty($this->newLeadEmail)) {
                    $q->orWhere('email', trim($this->newLeadEmail));
                }
            })
            ->first();

        if ($exists) {
            $this->addError('newLeadPhone', "Já existe um lead cadastrado com este telefone/e-mail: {$exists->name}");
            return;
        }

        UserList::create([
            'uuid' => (string) Str::limit(Str::uuid(), 8, ''),
            'user_id' => $userId,
            'name' => trim($this->newLeadName),
            'email' => !empty($this->newLeadEmail) ? trim($this->newLeadEmail) : null,
            'phone' => $cleanPhone,
            'status' => $this->newLeadStatus,
            'whatsapp_opt_in' => $this->newLeadWhatsappOptIn,
            'sms_opt_in' => $this->newLeadSmsOptIn,
        ]);

        $this->closeCreateLeadModal();
        $this->dispatch('leadCreated', 'Novo lead cadastrado com sucesso!');
    }

    public function clearImportSummary()
    {
        $this->reset('importSummary');
    }
}
