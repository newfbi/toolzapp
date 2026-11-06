<?php

namespace App\Http\Livewire\Admin\Sms;

use App\Models\Settings;
use App\Services\Sms\SmsProviderManager;
use Illuminate\Support\Facades\Log;
use Livewire\Component;

class SmsSettingsController extends Component
{
    public string $provider = '';

    public function mount(SmsProviderManager $providers): void
    {
        view()->share('title', 'Configurações SMS');
        $this->provider = $providers->activeKey();
    }

    public function render(SmsProviderManager $providers)
    {
        return view('livewire.admin.sms.settings', [
            'providers' => $providers->available(),
            'activeProvider' => $providers->activeKey(),
        ]);
    }

    public function save(SmsProviderManager $providers): void
    {
        $this->validate([
            'provider' => 'required|string|max:80',
        ], [
            'provider.required' => 'Selecione um provedor SMS.',
        ]);

        $available = $providers->available();
        if (!isset($available[$this->provider])) {
            $this->addError('provider', 'Esse provedor SMS não está disponível para esta instalação.');
            return;
        }

        try {
            $settings = Settings::query()->first();
            if (!$settings) {
                throw new \RuntimeException('Registro principal de configurações não encontrado.');
            }

            $settings->update(['sms_provider' => $this->provider]);
            session()->flash('message', 'Provedor SMS ativo atualizado com sucesso.');
        } catch (\Throwable $e) {
            Log::error('Falha ao alterar provedor SMS ativo', [
                'provider' => $this->provider,
                'error' => $e->getMessage(),
            ]);
            session()->flash('error', 'Não foi possível salvar o provedor SMS.');
        }
    }
}
