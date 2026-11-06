<div class="container-fluid">
    @include('layouts.partials.page-title', [
        'title' => 'Recarga de Créditos',
        'breadcrumbItems' => [
            ['label' => 'Home', 'url' => route('dashboard')],
            ['label' => 'Recarga de Créditos'],
        ],
    ])
    <div class="row">
        <div style="margin-top: 0.2rem">
            @include('layouts.includes.errors-and-messages')
        </div>
        <div class="col-lg-12">
            <div class="card">
                <div class="card-header">Comprar Créditos</div>
                <div class="card-body">
                    <div class="mb-4 row">
                        <div class="mt-4 col-xl-3 col-lg-3 col-md-6 col-sm-12">
                            <h6>Dados do Cliente:</h6>
                            <div> <strong>{{ $user->name }}</strong> </div>
                            <div><strong>Email:</strong> {{ $user->email }}</div>
                            <div><strong>Phone:</strong> {{ $user->phone }}</div>
                            <div><strong>CPF:</strong> {{ $user->cpf }}</div>
                        </div>
                        <div class="mt-4 col-xl-3 col-lg-3 col-md-12 col-sm-12">
                            <h6>Detalhes da Recarga:</h6>
                            <div><strong>Mínimo:</strong> R$
                                {{ App\Helpers\Utils::MoedaBr($settings->recharge ?? 0) }}</div>
                            <div class="form-group mt-2">
                                <label for="valor">Valor da Recarga (R$)</label>
                                <input type="text" class="form-control" wire:model.live="valor" placeholder="0,00">
                                @if ($valor > 0)
                                    <small class="font-weight-bold text-success">
                                        💰 R$ {{ number_format($valor, 2, ',', '.') }} =
                                        {{ number_format($creditosCalculados, 2, '.', '') }} em Créditos
                                    </small>
                                @else
                                    <small class="text-muted">Cada R$ 1,00 recarregado adiciona
                                        {{ number_format(1 / ($settings->token_value ?? 0.09), 2, '.', '') }} em
                                        Créditos.</small>
                                @endif
                            </div>
                        </div>
                        <div class="col-xl-6 col-lg-6 col-md-12 col-sm-12">
                            <div class="card">
                                <div class="card-header">
                                    <h5>Foma de Pagamento</h5>
                                </div>
                                <div class="card-body">
                                    <div class="d-flex nav custom-navstpes" id="justify-about-tab" role="tablist">
                                        <button class="flex-grow-1 text-center btn nav-link active" id="account-tab"
                                            data-bs-toggle="tab" data-bs-target="#pix-tab-pane" type="button"
                                            role="tab" aria-controls="pix-tab-pane" aria-selected="true">
                                            <span class="d-flex align-items-center mt-1">
                                                <span><i class="pe-2 ti ti-user-circle"></i></span>
                                                <span class="d-flex flex-column ms-3 text-start custom-title">
                                                    <span class="f-w-500 f-s-16">Pagamento via PIX</span>
                                                </span>
                                            </span>
                                        </button>
                                    </div>
                                    <div class="mt-3 tab-content" id="justify-LightContent">
                                        <div class="tab-pane fade show active" id="pix-tab-pane" role="tabpanel"
                                            aria-labelledby="account-tab" tabindex="-1">
                                            @if ($paymentOpenPix)
                                                <div class="settings-form" wire:poll.40s="checkPaymentStatus">
                                                    <h5 class="mb-4 text-primary">
                                                        Método de Pagamento
                                                    </h5>
                                                    @php
                                                        $qrCodeSrc = $paymentOpenPix->qr_code ?? null;
                                                        if (
                                                            $qrCodeSrc &&
                                                            !\Illuminate\Support\Str::startsWith($qrCodeSrc, 'data:')
                                                        ) {
                                                            $qrCodeSrc = 'data:image/png;base64,' . $qrCodeSrc;
                                                        }
                                                    @endphp
                                                    @if ($qrCodeSrc)
                                                        <img width="250" id="qrCodeImage" src="{{ $qrCodeSrc }}"
                                                            alt="QR Code PIX">
                                                    @endif
                                                    <p>ou copie a chave PIX:
                                                        <strong>{{ Str::limit($paymentOpenPix->pix_code, 15, '...') }}</strong>
                                                    </p>
                                                    <input type="hidden" id="copy"
                                                        value="{{ $paymentOpenPix->pix_code }}" readonly>
                                                    <button class="btn btn-primary btn-xs"
                                                        onclick="click_copyCode()">Copiar Código</button>
                                                    <button type="button" class="btn btn-danger btn-xs"
                                                        wire:click="cancelPayment">Cancelar</button>
                                                    <div class="mt-2">
                                                        <small class="text-info">
                                                            <i class="ti ti-refresh"></i> Verificação automática a cada
                                                            40 segundos
                                                        </small>
                                                    </div>
                                                    <p>Aguardando pagamento...</p>
                                                </div>
                                            @else
                                                <section class="panel">
                                                    <div class="p-5 text-center overlay-box">
                                                        <h4 class="mt-3 mb-0 text-primary">Gerar novo QRCODE</h4>
                                                    </div>
                                                    <div class="panel-body">
                                                        <center>
                                                            <button type="submit" class="btn btn-primary"
                                                                wire:click="generatePayment">Gerar QRCODE</button>
                                                        </center>
                                                    </div>
                                                </section>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

@section('js')
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        $(document).ready(function() {
            window.click_copyCode = async function() {
                await copyCode();
            }

            let Toast = Swal.mixin({
                toast: true,
                position: 'bottom-end',
                showConfirmButton: false,
                timerProgressBar: true,
                didOpen: (toast) => {
                    toast.addEventListener('mouseenter', Swal.stopTimer)
                    toast.addEventListener('mouseleave', Swal.resumeTimer)
                }
            });

            function copyCode() {
                var copyText = document.getElementById("copy");
                copyText.readOnly = true;
                copyText.type = 'text';
                copyText.select();
                document.execCommand("copy");
                Toast.fire({
                    title: 'Código Pix, copiado com sucesso!',
                    icon: 'success',
                    timer: 2000
                });
            }
        })
    </script>
@endsection
