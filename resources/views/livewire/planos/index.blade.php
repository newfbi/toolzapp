<div class="container-fluid">
    @include('layouts.partials.page-title', [
        'title' => 'Planos',
        'breadcrumbItems' => [['label' => 'Home', 'url' => route('dashboard')], ['label' => 'Planos']],
    ])

    <div class="mb-10 row row-sm">
        <div class="col-12">
            <div class="mb-10 text-center pricing-title">
                <h4>Planos</h4>
                <p class="text-muted">Escolha o plano ideal para o seu negócio.</p>
            </div>

            <div class="row g-4">
                @foreach ($plans as $p)
                    @php
                        $isHighlight = $p->id === $highlightId;
                        $rolesRaw = $p->roles ?? null;
                        $rolesList = is_array($rolesRaw)
                            ? $rolesRaw
                            : (is_string($rolesRaw)
                                ? (json_decode($rolesRaw, true) ?:
                                [])
                                : []);
                        $img = $p->img ?: 'assets/images/pages/starter.png';
                    @endphp

                    <div class="col-12 col-md-6 col-xl-3">
                        <div class="card mb-0 {{ $isHighlight ? 'ribbon right' : '' }} overflow-hidden">
                            @if ($isHighlight)
                                <span class="ribbon2 ribbon-primary"><span>Popular</span></span>
                            @endif

                            <div class="card-body">
                                <div class="text-center">
                                    <img src="{{ $img }}" alt="{{ $p->name }}" class="w-80px img-fluid">
                                    <div class="d-flex justify-content-center mt-2">
                                        <sup class="me-1 mt-3 mb-0 h4">R$</sup>
                                        <h2 class="mb-0 fs-45 fw-normal">
                                            {{ number_format((float) ($p->price ?? 0), 2, ',', '.') }}</h2>
                                    </div>
                                    <p class="mt-2 mb-6 text-primary fw-medium fs-16">
                                        @if ($p->status === 'enabled')
                                            Disponível por: {{ (int) ($p->mensalidade ?? 0) }}
                                            {{ (int) ($p->mensalidade ?? 0) === 1 ? 'dia' : 'dias' }}
                                        @elseif ($p->status === 'disabled')
                                            Indisponível
                                        @else
                                            Cancelado
                                        @endif
                                    </p>
                                </div>

                                <ul class="list-unstyled fs-15">
                                    @php
                                        $baseCredits = $p->price / ($settings->token_value ?? 0.09);
                                        $totalCredits = $baseCredits * (($p->credits ?? 0) / 100);
                                    @endphp
                                    <li class="mb-3">
                                        <i class="me-2 text-primary bi bi-check-lg"></i>
                                        <span>Créditos de Tokens:
                                            {{ number_format($totalCredits, 0, ',', '.') }}
                                            <i class="ms-1 text-info bi bi-info-circle" data-bs-toggle="tooltip"
                                                data-bs-placement="top"
                                                title="Tokens de uso de inteligência artificial"></i>
                                        </span>
                                    </li>
                                    <li class="mb-3"><i class="me-2 text-primary bi bi-check-lg"></i><span>Agentes de
                                            AI:
                                            {{ $p->agentes ?? 0 }}</span></li>
                                    <li class="mb-3"><i class="me-2 text-primary bi bi-check-lg"></i><span>Instâncias
                                            Whatsapp:
                                            {{ $p->instances ?? 0 }}</span></li>
                                    <li class="mb-3">
                                        @if ($loop->first)
                                            <i class="me-2 text-secondary bi bi-x-lg"></i>
                                        @else
                                            <i class="me-2 text-primary bi bi-check-lg"></i>
                                        @endif
                                        <span>Utilização de API de terceiros
                                            <i class="ms-1 text-info bi bi-info-circle" data-bs-toggle="tooltip"
                                                data-bs-placement="top"
                                                title="API externa de utilização de Modelos, exemplo: OpenAI, Anthropic (Claude), Google (Gemini)"></i>
                                        </span>
                                    </li>
                                    <li class="mb-3"><i class="me-2 text-primary bi bi-check-lg"></i><span>Comissão
                                            por Indicação:
                                            {{ isset($p->indication) ? number_format((float) $p->indication, 2, ',', '.') . '%' : '--' }}</span>
                                    </li>
                                    <li class="mb-3"><i class="me-2 text-primary bi bi-check-lg"></i><span>Bônus de
                                            Mensalidade:
                                            {{ isset($p->commission) ? number_format((float) $p->commission, 2, ',', '.') . '%' : '--' }}</span>
                                    </li>
                                </ul>

                                <div class="mt-6 text-center">
                                    <button type="button" class="btn btn-primary"
                                        wire:click="contratar({{ $p->id }})">
                                        Contratar
                                    </button>
                                </div>

                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</div>

@section('js')
    <script>
        // Inicializar tooltips do Bootstrap
        document.addEventListener('DOMContentLoaded', function() {
            var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
            var tooltipList = tooltipTriggerList.map(function(tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl);
            });
        });

        // Reinicializar tooltips após atualizações do Livewire
        document.addEventListener('livewire:init', function() {
            Livewire.hook('morph.updated', ({
                el,
                component
            }) => {
                var tooltipTriggerList = [].slice.call(el.querySelectorAll('[data-bs-toggle="tooltip"]'));
                var tooltipList = tooltipTriggerList.map(function(tooltipTriggerEl) {
                    return new bootstrap.Tooltip(tooltipTriggerEl);
                });
            });
        });
    </script>
@endsection
