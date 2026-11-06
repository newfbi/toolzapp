<div class="container-fluid">
    @include('layouts.partials.page-title', [
        'title' => 'Configurações SMS',
        'breadcrumbItems' => [
            ['label' => 'Home', 'url' => route('admin')],
            ['label' => 'SMS'],
        ],
    ])

    <div class="row row-sm">
        <div class="col-12">
            @include('layouts.includes.errors-and-messages')
        </div>

        <div class="col-xl-8">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-1">Provedor de envio</h5>
                    <p class="mb-0 text-muted">A troca vale somente para novas filas. Disparos já criados mantêm o provedor original.</p>
                </div>
                <form wire:submit="save">
                    <div class="card-body">
                        <label class="form-label">Provedor SMS ativo</label>
                        <select wire:model="provider" class="form-select @error('provider') is-invalid @enderror">
                            @foreach ($providers as $item)
                                <option value="{{ $item['key'] }}">{{ $item['label'] }}</option>
                            @endforeach
                        </select>
                        @error('provider') <div class="invalid-feedback">{{ $message }}</div> @enderror

                        <div class="mt-3 alert alert-info mb-0">
                            <i class="ri-information-line me-1"></i>
                            As credenciais permanecem fora do banco e são lidas do <code>.env</code>. A Infobip usa API Key; a ComprarSMS, quando selecionada, mantém a autenticação JWT automática.
                        </div>
                    </div>
                    <div class="card-footer text-end">
                        <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">
                            <i class="ri-save-line me-1"></i>Salvar provedor
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <div class="col-xl-4">
            <div class="card">
                <div class="card-header"><h5 class="mb-0">Status dos adaptadores</h5></div>
                <div class="card-body">
                    @foreach ($providers as $item)
                        <div class="d-flex align-items-center justify-content-between border-bottom py-2">
                            <div>
                                <div class="fw-semibold">{{ $item['label'] }}</div>
                                <small class="text-muted">{{ $item['key'] }}</small>
                            </div>
                            <span class="badge {{ $item['configured'] ? 'bg-success' : 'bg-warning text-dark' }}">
                                {{ $item['configured'] ? 'Configurado' : 'Pendente' }}
                            </span>
                        </div>
                    @endforeach
                    <div class="mt-3 small text-muted">
                        Ativo agora: <strong>{{ $providers[$activeProvider]['label'] ?? $activeProvider }}</strong>
                    </div>
                    @if ($activeProvider === 'infobip')
                        <div class="mt-3 alert alert-light border small mb-0">
                            <strong>Parâmetros atuais:</strong><br>
                            até {{ number_format((int) config('services.infobip.max_destinations_per_request', 50000), 0, ',', '.') }} destinos por requisição da API;
                            fila em {{ number_format((int) config('services.sms.batch_size', 500), 0, ',', '.') }} destinatários por lote,
                            intervalo de {{ (int) config('services.sms.batch_delay_seconds', 15) }}s e consulta de status por até {{ (int) config('services.sms.status_poll_hours', 48) }}h.
                        </div>
                    @endif
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h5 class="mb-0">Webhook</h5></div>
                <div class="card-body small">
                    <p class="mb-2">Configure no provedor o endereço abaixo:</p>
                    <code class="d-block text-break">{{ route('sms.webhook', ['provider' => $activeProvider]) }}</code>
                    <p class="mt-2 mb-0 text-muted">
                        Para Infobip, use uma assinatura HMAC-SHA256 com o header <code>X-Hub-Signature</code>.
                        A fila também consulta os relatórios automaticamente como fallback.
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>
