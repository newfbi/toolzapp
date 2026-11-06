<?php

namespace App\Http\Livewire\UserApi;

use App\Models\Platform;
use App\Models\UserApi;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;

class CreateApiController extends Component
{
    public $apis = [];
    public $showModal = false;

    // Campos do modal
    public $selectedPlatform = '';
    public $apiKey = '';
    public $availablePlatforms = [];

    public function mount()
    {
        view()->share('title', 'Gerenciar APIs');
        $this->loadApis();
        $this->loadAvailablePlatforms();
    }

    public function loadApis()
    {
        $this->apis = UserApi::with('platform')
            ->where('user_id', Auth::user()->uuid)
            ->get();
    }

    public function loadAvailablePlatforms()
    {
        $userPlatformSlugs = $this->apis->pluck('platform_slug')->toArray();
        $this->availablePlatforms = Platform::active()
            ->whereNotIn('slug', $userPlatformSlugs)
            ->ordered()
            ->get();
    }

    public function render()
    {
        return view('livewire.user_api.create');
    }

    public function openModal()
    {
        $this->resetModal();
        $this->showModal = true;
    }

    public function closeModal()
    {
        $this->showModal = false;
        $this->resetModal();
    }

    private function resetModal()
    {
        $this->selectedPlatform = '';
        $this->apiKey = '';
        $this->resetValidation();
    }

    protected $rules = [
        'selectedPlatform' => 'required|exists:platforms,slug',
        'apiKey' => 'required|string|min:10|max:1000',
    ];

    protected $messages = [
        'selectedPlatform.required' => 'Selecione uma plataforma.',
        'selectedPlatform.exists' => 'Plataforma inválida.',
        'apiKey.required' => 'A chave da API é obrigatória.',
        'apiKey.min' => 'A chave da API deve ter pelo menos 10 caracteres.',
        'apiKey.max' => 'A chave da API deve ter no máximo 1000 caracteres.',
    ];

    public function saveApi()
    {
        $this->validate();

        // Verificar se o usuário já tem uma API para esta plataforma
        if (UserApi::platformExistsForUser(Auth::user()->uuid, $this->selectedPlatform)) {
            $this->addError('selectedPlatform', 'Você já possui uma API configurada para esta plataforma.');
            return;
        }

        UserApi::create([
            'user_id' => Auth::user()->uuid,
            'platform_slug' => $this->selectedPlatform,
            'api_key' => $this->apiKey,
            'is_active' => true,
        ]);

        $this->closeModal();
        $this->loadApis();
        $this->loadAvailablePlatforms();

        session()->flash('message', 'API cadastrada com sucesso!');
    }

    public function toggleApiStatus($apiId)
    {
        $api = UserApi::findOrFail($apiId);

        // Verificar se é do usuário
        if ($api->user_id !== Auth::user()->uuid) {
            abort(403);
        }

        $api->update(['is_active' => !$api->is_active]);

        $this->loadApis();
        $status = $api->is_active ? 'ativada' : 'desativada';
        session()->flash('message', "API {$status} com sucesso!");
    }


    public function showDeleteConfirm($apiId)
    {
        $api = UserApi::findOrFail($apiId);

        // Verificar se é do usuário
        if ($api->user_id !== Auth::user()->uuid) {
            abort(403);
        }

        $platformName = $api->platform->name ?? 'API';

        // Disparar evento para mostrar confirmação
        $this->dispatch('showDeleteApiConfirm', [
            'id' => $apiId,
            'platformName' => $platformName
        ]);
    }

    #[On('handleDeleteApi')]
    public function deleteUserApi($apiId)
    {
        $api = UserApi::findOrFail($apiId);

        // Verificar se é do usuário
        if ($api->user_id !== Auth::user()->uuid) {
            abort(403);
        }

        $platformName = $api->platform->name ?? 'API';

        $api->delete();

        $this->loadApis();
        $this->loadAvailablePlatforms();

        // Disparar evento de sucesso
        $this->dispatch('apiDeleted', "API {$platformName} removida com sucesso!");
    }

    /**
     * Retorna o ícone apropriado para um recurso
     */
    public function getFeatureIcon($feature)
    {
        $icons = [
            'chat' => 'chat-1-line',
            'image' => 'image-line',
            'audio' => 'music-line',
            'speech' => 'voiceprint-line',
            'embeddings' => 'database-line',
            'text-generation' => 'file-text-line',
            'code' => 'code-line',
            'multimodal' => 'gallery-line',
        ];

        return $icons[$feature] ?? 'settings-line';
    }
}
