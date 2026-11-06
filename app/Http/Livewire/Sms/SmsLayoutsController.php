<?php

namespace App\Http\Livewire\Sms;

use App\Models\SmsLayout;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

class SmsLayoutsController extends Component
{
    use WithPagination;

    protected $paginationTheme = 'bootstrap';

    public string $search = '';
    public string $filterStatus = '';
    public ?string $layoutId = null;
    public string $name = '';
    public string $templateName = '';
    public string $message = '';

    public function mount(): void
    {
        view()->share('title', 'Temas SMS');
    }

    public function updatingSearch(): void { $this->resetPage(); }
    public function updatingFilterStatus(): void { $this->resetPage(); }

    public function render()
    {
        $query = SmsLayout::forUser(Auth::user()->uuid)->orderBy('updated_at', 'desc');
        if ($this->search !== '') {
            $term = '%' . $this->search . '%';
            $query->where(fn ($q) => $q->where('name', 'like', $term)->orWhere('message', 'like', $term));
        }
        if ($this->filterStatus !== '') {
            $query->where('status', $this->filterStatus);
        }

        return view('livewire.sms.layouts', [
            'layouts' => $query->paginate(12),
            'variables' => SmsLayout::availableVariables(),
        ]);
    }

    #[Computed]
    public function preview(): string
    {
        return SmsLayout::renderContent($this->message, [
            'nome' => 'João Silva',
            'primeiro_nome' => 'João',
            'email' => 'joao@email.com',
            'telefone' => '11999998888',
        ]);
    }

    public function openCreateModal(): void
    {
        $this->reset(['layoutId', 'name', 'templateName', 'message']);
        $this->resetErrorBag();
        $this->dispatch('openSmsLayoutEditor');
    }

    public function openEditModal(string $uuid): void
    {
        $layout = SmsLayout::forUser(Auth::user()->uuid)->where('uuid', $uuid)->first();
        if (!$layout) {
            session()->flash('error', 'Tema SMS não encontrado.');
            return;
        }

        $this->layoutId = $layout->uuid;
        $this->name = $layout->name;
        $this->templateName = (string) $layout->template_name;
        $this->message = $layout->message;
        $this->resetErrorBag();
        $this->dispatch('openSmsLayoutEditor');
    }

    public function save(): void
    {
        $this->validate([
            'name' => 'required|string|max:255',
            'templateName' => 'nullable|string|max:255',
            'message' => 'required|string|max:765',
        ], [
            'name.required' => 'Informe um nome para o tema SMS.',
            'message.required' => 'Escreva o conteúdo do SMS.',
            'message.max' => 'A mensagem deve ter no máximo 765 caracteres. O limite real de partes é validado antes do envio.',
        ]);

        $userId = Auth::user()->uuid;
        try {
            $data = [
                'name' => trim($this->name),
                'template_name' => trim($this->templateName) ?: null,
                'message' => trim($this->message),
            ];

            if ($this->layoutId) {
                $layout = SmsLayout::forUser($userId)->where('uuid', $this->layoutId)->first();
                if (!$layout) {
                    session()->flash('error', 'Tema SMS não encontrado.');
                    return;
                }
                $layout->update($data);
                session()->flash('message', 'Tema SMS atualizado com sucesso!');
            } else {
                SmsLayout::create($data + ['user_id' => $userId, 'status' => 'active']);
                session()->flash('message', 'Tema SMS criado com sucesso!');
            }

            $this->closeEditor();
        } catch (\Throwable $e) {
            Log::error('Erro ao salvar tema SMS', ['user_id' => $userId, 'error' => $e->getMessage()]);
            session()->flash('error', 'Erro ao salvar o tema SMS.');
        }
    }

    public function toggleStatus(string $uuid): void
    {
        $layout = SmsLayout::forUser(Auth::user()->uuid)->where('uuid', $uuid)->first();
        if ($layout) {
            $layout->update(['status' => $layout->status === 'active' ? 'inactive' : 'active']);
        }
    }

    public function duplicate(string $uuid): void
    {
        $layout = SmsLayout::forUser(Auth::user()->uuid)->where('uuid', $uuid)->first();
        if (!$layout) {
            session()->flash('error', 'Tema SMS não encontrado.');
            return;
        }

        SmsLayout::create([
            'user_id' => Auth::user()->uuid,
            'name' => mb_substr($layout->name . ' (cópia)', 0, 255),
            'template_name' => $layout->template_name,
            'message' => $layout->message,
            'status' => 'active',
        ]);
        session()->flash('message', 'Tema SMS duplicado com sucesso!');
    }

    public function showDeleteConfirm(string $uuid, string $name): void
    {
        $this->dispatch('showDeleteSmsLayoutConfirm', ['id' => $uuid, 'name' => $name]);
    }

    #[On('deleteSmsLayout')]
    public function deleteLayout(string $uuid): void
    {
        $layout = SmsLayout::forUser(Auth::user()->uuid)->where('uuid', $uuid)->first();
        if (!$layout) {
            $this->dispatch('error', 'Tema SMS não encontrado.');
            return;
        }

        $layout->delete();
        $this->dispatch('smsLayoutDeleted', 'Tema SMS excluído com sucesso!');
    }

    public function closeEditor(): void
    {
        $this->reset(['layoutId', 'name', 'templateName', 'message']);
        $this->resetErrorBag();
        $this->dispatch('closeSmsLayoutEditor');
    }
}
