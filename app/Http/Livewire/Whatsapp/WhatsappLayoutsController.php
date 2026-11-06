<?php

namespace App\Http\Livewire\Whatsapp;

use App\Models\WhatsappLayout;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;

class WhatsappLayoutsController extends Component
{
    use WithFileUploads;

    /** Limite prático de caracteres por mensagem do WhatsApp */
    private const MAX_MESSAGE_LENGTH = 4096;

    /** Tamanho máximo da imagem anexa (KB) */
    private const MAX_IMAGE_SIZE_KB = 5120;

    // Listagem
    public $search = '';
    public $filterStatus = '';

    // Editor (modal)
    public ?string $layoutId = null;   // null = criando
    public string $name = '';
    public string $message = '';
    public ?string $mediaType = null;      // null | 'image'
    public ?string $mediaSource = null;    // caminho local (disco público) ou URL externa
    public $newImage;                      // upload temporário (Livewire)
    public string $externalImageUrl = '';  // campo de URL externa

    public function mount()
    {
        view()->share('title', 'Mensagens Prontas WhatsApp');
    }

    public function updatingSearch() { $this->resetPage(); }
    public function updatingFilterStatus() { $this->resetPage(); }

    public function render()
    {
        $userId = Auth::user()->uuid;

        $query = WhatsappLayout::forUser($userId)
            ->orderBy('updated_at', 'desc');

        if ($this->search) {
            $term = '%' . $this->search . '%';
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', $term)
                    ->orWhere('message', 'like', $term);
            });
        }

        if ($this->filterStatus) {
            $query->where('status', $this->filterStatus);
        }

        return view('livewire.whatsapp.layouts', [
            'layouts' => $query->paginate(12),
            'variables' => WhatsappLayout::availableVariables(),
            'maxMessageLength' => self::MAX_MESSAGE_LENGTH,
        ]);
    }

    /**
     * Prévia da mensagem com dados de exemplo de um lead,
     * formatada como o WhatsApp renderiza (links, negrito etc.)
     */
    #[Computed]
    public function preview(): array
    {
        $sample = [
            'nome' => 'João Silva',
            'primeiro_nome' => 'João',
            'email' => 'joao@email.com',
            'telefone' => '(11) 99999-8888',
            'status' => 'Ativo',
        ];

        return [
            'text' => WhatsappLayout::formatForPreview(
                WhatsappLayout::renderContent($this->message ?? '', $sample)
            ),
            'imageUrl' => $this->currentImageDisplayUrl(),
        ];
    }

    /**
     * URL exibível da imagem atual do editor (existente, nova ou externa)
     */
    public function currentImageDisplayUrl(): ?string
    {
        // Upload ainda não salvo -> preview temporário do Livewire
        if ($this->newImage) {
            return $this->newImage->temporaryUrl();
        }

        // URL externa digitada e ainda não salva
        $external = trim($this->externalImageUrl);
        if ($external !== '') {
            return $external;
        }

        // Mídia já salva no layout
        if ($this->mediaType === 'image' && $this->mediaSource) {
            if (str_starts_with($this->mediaSource, 'http')) {
                return $this->mediaSource;
            }
            return \Storage::disk('public')->url($this->mediaSource);
        }

        return null;
    }

    public function openCreateModal()
    {
        $this->reset(['layoutId', 'name', 'message', 'mediaType', 'mediaSource', 'newImage', 'externalImageUrl']);
        $this->resetErrorBag();
        $this->dispatch('openLayoutEditor');
    }

    public function openEditModal(string $layoutUuid)
    {
        $layout = WhatsappLayout::forUser(Auth::user()->uuid)->where('uuid', $layoutUuid)->first();
        if (!$layout) {
            session()->flash('error', 'Layout não encontrado.');
            return;
        }

        $this->layoutId = $layout->uuid;
        $this->name = $layout->name;
        $this->message = $layout->message;
        $this->mediaType = $layout->media_type;
        $this->mediaSource = $layout->media_source;
        $this->reset(['newImage', 'externalImageUrl']);
        $this->resetErrorBag();
        $this->dispatch('openLayoutEditor');
    }

    /**
     * Salva o layout: valida, resolve a mídia anexa (upload > URL externa >
     * manter existente) e persiste. Arquivo local antigo é removido se trocado.
     */
    public function save()
    {
        $this->validate([
            'name' => 'required|string|max:255',
            'message' => 'required|string|max:' . self::MAX_MESSAGE_LENGTH,
            'newImage' => 'nullable|image|mimes:jpg,jpeg,png,webp,gif|max:' . self::MAX_IMAGE_SIZE_KB,
            'externalImageUrl' => 'nullable|url|max:500',
        ], [
            'name.required' => 'Informe um nome para identificar o layout.',
            'message.required' => 'Escreva o conteúdo da mensagem.',
            'message.max' => 'A mensagem deve ter no máximo ' . self::MAX_MESSAGE_LENGTH . ' caracteres.',
            'newImage.image' => 'O arquivo deve ser uma imagem.',
            'newImage.mimes' => 'Formatos aceitos: JPG, PNG, WEBP ou GIF.',
            'newImage.max' => 'A imagem deve ter no máximo 5 MB.',
            'externalImageUrl.url' => 'Informe uma URL válida começando com http:// ou https://',
        ]);

        $userId = Auth::user()->uuid;

        try {
            // Localiza o layout existente (se edição) antes de resolver a mídia
            $existing = null;
            if ($this->layoutId) {
                $existing = WhatsappLayout::forUser($userId)->where('uuid', $this->layoutId)->first();
                if (!$existing) {
                    session()->flash('error', 'Layout não encontrado.');
                    return;
                }
            }

            // Resolve mídia (upload > URL externa > manter atual)
            [$mediaType, $mediaSource] = $this->resolveMedia($existing);

            if ($existing) {
                $existing->update([
                    'name' => $this->name,
                    'message' => $this->message,
                    'media_type' => $mediaType,
                    'media_source' => $mediaSource,
                ]);
                session()->flash('message', 'Layout atualizado com sucesso!');
            } else {
                WhatsappLayout::create([
                    'user_id' => $userId,
                    'name' => $this->name,
                    'message' => $this->message,
                    'media_type' => $mediaType,
                    'media_source' => $mediaSource,
                    'status' => 'active',
                ]);
            }

            $this->dispatch('closeLayoutEditor');
            $this->reset(['layoutId', 'name', 'message', 'mediaType', 'mediaSource', 'newImage', 'externalImageUrl']);
        } catch (\Exception $e) {
            Log::error('Erro ao salvar layout WhatsApp', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            session()->flash('error', 'Erro ao salvar o layout: ' . $e->getMessage());
        }
    }

    /**
     * Decide a mídia do layout na ordem: novo upload > URL externa >
     * manter a atual. Remove arquivo local antigo quando substituído.
     *
     * @return array{0: ?string, 1: ?string} [mediaType, mediaSource]
     */
    private function resolveMedia(?WhatsappLayout $existing): array
    {
        // 1. Upload novo
        if ($this->newImage) {
            if ($existing && $existing->isLocalMedia()) {
                \Storage::disk('public')->delete($existing->media_source);
            }
            return ['image', $this->newImage->store('whatsapp-layouts', 'public')];
        }

        // 2. URL externa preenchida
        $external = trim($this->externalImageUrl);
        if ($external !== '') {
            if ($existing && $existing->isLocalMedia()) {
                \Storage::disk('public')->delete($existing->media_source);
            }
            return ['image', $external];
        }

        // 3. Sem upload/URL: mantém a mídia existente
        if ($existing) {
            return [$existing->media_type, $existing->media_source];
        }

        return [null, null];
    }

    /**
     * Remove a imagem anexa do layout em edição (arquivo local + registro).
     */
    public function removeImage(): void
    {
        if ($this->layoutId) {
            $layout = WhatsappLayout::forUser(Auth::user()->uuid)->where('uuid', $this->layoutId)->first();
            if ($layout && $layout->isLocalMedia()) {
                \Storage::disk('public')->delete($layout->media_source);
            }
            if ($layout) {
                $layout->update(['media_type' => null, 'media_source' => null]);
            }
        }

        $this->reset(['mediaType', 'mediaSource', 'newImage', 'externalImageUrl']);
    }

    public function closeEditor()
    {
        $this->reset(['layoutId', 'name', 'message', 'mediaType', 'mediaSource', 'newImage', 'externalImageUrl']);
        $this->resetErrorBag();
        $this->dispatch('closeLayoutEditor');
    }

    public function toggleStatus(string $layoutUuid)
    {
        $layout = WhatsappLayout::forUser(Auth::user()->uuid)->where('uuid', $layoutUuid)->first();
        if (!$layout) {
            session()->flash('error', 'Layout não encontrado.');
            return;
        }

        $layout->update(['status' => $layout->status === 'active' ? 'inactive' : 'active']);
    }

    public function duplicate(string $layoutUuid)
    {
        $layout = WhatsappLayout::forUser(Auth::user()->uuid)->where('uuid', $layoutUuid)->first();
        if (!$layout) {
            session()->flash('error', 'Layout não encontrado.');
            return;
        }

        // Copia também o arquivo local de imagem, se houver
        $mediaSource = $layout->media_source;
        if ($layout->isLocalMedia()) {
            $newPath = 'whatsapp-layouts/' . Str::uuid() . '.' . pathinfo($layout->media_source, PATHINFO_EXTENSION);
            \Storage::disk('public')->copy($layout->media_source, $newPath);
            $mediaSource = $newPath;
        }

        WhatsappLayout::create([
            'user_id' => Auth::user()->uuid,
            'name' => mb_substr($layout->name . ' (cópia)', 0, 255),
            'message' => $layout->message,
            'media_type' => $layout->media_type,
            'media_source' => $mediaSource,
        ]);

        session()->flash('message', 'Layout duplicado com sucesso!');
    }

    public function showDeleteConfirm(string $layoutUuid, string $layoutName)
    {
        $this->dispatch('showDeleteLayoutConfirm', [
            'id' => $layoutUuid,
            'name' => $layoutName,
        ]);
    }

    #[On('deleteLayout')]
    public function deleteLayout(string $layoutId)
    {
        $layout = WhatsappLayout::forUser(Auth::user()->uuid)->where('uuid', $layoutId)->first();
        if (!$layout) {
            $this->dispatch('error', 'Layout não encontrado.');
            return;
        }

        // Remove o arquivo local antes do registro
        if ($layout->isLocalMedia()) {
            \Storage::disk('public')->delete($layout->media_source);
        }
        $layout->delete();

        $this->dispatch('layoutDeleted', 'Layout excluído com sucesso!');
    }
}
