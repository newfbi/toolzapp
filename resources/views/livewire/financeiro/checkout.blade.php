<div class="container-fluid" {{ !$isActive ? 'wire:poll.60s=checkPaymentStatus' : null }}>
    @include('layouts.partials.page-title', [
        'title' => 'Checkout',
        'breadcrumbItems' => [['label' => 'Home', 'url' => route('dashboard')], ['label' => 'Checkout']],
    ])
    <div class="row">
        <div style="margin-top: 0.2rem">
            @include('layouts.includes.errors-and-messages')
            @if (in_array($payment->billingType, ['PIX']) && in_array($payment->status, ['pago', 'creditado']))
                <div class="mt-2 alert alert-success" role="alert">
                    <strong>Pagamento confirmado!</strong> Este pedido já foi pago via PIX.
                </div>
            @endif
        </div>
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header">
                    <h5>Formas de Pagamento</h5>
                </div>
                <div class="card-body">
                    <div class="d-flex nav custom-navstpes" id="justify-about-tab" role="tablist">
                        <button class="flex-grow-1 text-center btn nav-link {{ $currentTab === 'pix' ? 'active' : '' }}"
                            wire:click="$set('currentTab', 'pix')" type="button" role="tab">
                            <span class="d-flex align-items-center mt-1">
                                <span><i class="pe-2 ti ti-currency-dollar"></i></span>
                                <span class="d-flex flex-column ms-3 text-start custom-title">
                                    <span class="f-w-500 f-s-16">Pagamento via PIX</span>
                                </span>
                            </span>
                        </button>
                    </div>
                    <div class="mt-3 tab-content" id="justify-LightContent">
                        @if (!$isActive)
                            <div class="tab-pane fade {{ $currentTab === 'pix' ? 'show active' : '' }}"
                                id="pix-tab-pane" role="tabpanel" tabindex="-1">
                                <div class="shadow-none card">
                                    <div class="select-content card-body">
                                        <div class="p-5 text-center overlay-box">
                                            <h4 class="mt-3 mb-0 text-primary">Gerar novo
                                                QRCODE</h4>
                                        </div>
                                        <div>
                                            @if (!$readyForPix)
                                                <div class="alert alert-warning">
                                                    <strong>Antes de gerar o QR Code:</strong> complete seu nome, CPF e
                                                    e-mail no seu perfil.
                                                    <a href="{{ route('profile') }}"
                                                        class="ms-2 btn-outline-primary btn btn-sm">Ir para Perfil</a>
                                                </div>
                                            @elseif ($paymentOpenPix)
                                                <div class="text-center settings-form"
                                                    wire:poll.40s="checkPaymentStatus">
                                                    <h5 class="mb-2 text-muted">
                                                        Valor do
                                                        processo: R$
                                                        {{ number_format($valor_servico, 2, ',', '.') }}
                                                    </h5>

                                                    @php
                                                        $qrCodeSrc = $paymentOpenPix->qr_code ?? null;
                                                        if (empty($qrCodeSrc) && ($paymentOpenPix->pix_code ?? null)) {
                                                            $qrCodeSrc = \App\Helpers\Utils::generateQrCodeBase64(
                                                                $paymentOpenPix->pix_code,
                                                            );
                                                        }
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
                                                        <strong>{{ Str::limit($paymentOpenPix->pix_code ?? null, 15, '...') }}</strong>
                                                    </p>
                                                    <input type="hidden" id="copy"
                                                        value="{{ $paymentOpenPix->pix_code ?? null }}" readonly>
                                                    <button class="btn btn-primary btn-xs"
                                                        onclick="click_copyCode()">Copiar
                                                        Código</button>
                                                    <div class="mt-2">
                                                        <small class="text-info">
                                                            <i class="ti ti-refresh"></i> Verificação automática a cada
                                                            40 segundos
                                                        </small>
                                                    </div>
                                                    <p>Aguardando pagamento...
                                                    </p>
                                                </div>
                                            @else
                                                <div class="text-center settings-form">
                                                    <h6 class="mb-2 text-muted">
                                                        Valor do processo: R$
                                                        {{ number_format($valor_servico, 2, ',', '.') }}
                                                    </h6>
                                                    <button class="btn btn-primary btn-xs"
                                                        @if (in_array($payment->status, ['pago', 'creditado'])) disabled title="Pagamento já confirmado" @else wire:click="generatePixPayment" @endif>
                                                        {{ in_array($payment->status, ['pago', 'creditado']) ? 'Pagamento confirmado' : 'Gerar QrCode' }}
                                                    </button>
                                                </div>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            </div>
                            {{-- <div class="tab-pane fade {{ $currentTab === 'cartao' ? 'show active' : '' }}"
                                id="cartao-tab-pane" role="tabpanel" tabindex="-1">
                                <div class="shadow-none card">
                                    <div class="select-content card-body">
                                        <div class="p-5 text-center overlay-box">
                                            <h4 class="mt-3 mb-0">Cartão de Crédito</h4>
                                        </div>
                                        <div>
                                            <div class="text-center settings-form">
                                                <h5 class="mb-2 text-muted">
                                                    Valor
                                                    do
                                                    processo: R$
                                                    {{ number_format($valor_servico_parcela, 2, ',', '.') }}
                                                </h5>
                                                <p class="mb-2" style="color: rgba(var(--dark),.75)">
                                                    Valor do
                                                    Juros: R$
                                                    {{ number_format($jurosParcela, 2, ',', '.') }}
                                                </p>
                                                <p class="mb-2" style="color: rgba(var(--dark),.75)">
                                                    Valor por
                                                    parcela:
                                                    {{ $parcelas }} X R$
                                                    {{ number_format($valor_servico_parcela / $parcelas, 2, ',', '.') }}
                                                </p>
                                            </div>
                                            <form class="app-form" wire:submit="processCardPayment">
                                                <div class="row">
                                                    <div class="col-6">
                                                        <div class="mb-3">
                                                            <label class="form-label">Nome
                                                                Completo</label>
                                                            <input type="text" class="form-control" name="name"
                                                                wire:model='name'
                                                                placeholder="Digite o Nome igual ao do cartão">
                                                        </div>
                                                    </div>
                                                    <div class="col-6">
                                                        <div class="mb-3">
                                                            <label class="form-label">CPF do
                                                                Titular</label>
                                                            <input type="text" class="form-control" name="cpf"
                                                                wire:model='cpf'
                                                                placeholder="Digite o CPF do titular do cartão">
                                                        </div>
                                                    </div>
                                                    <div class="col-12">
                                                        <div class="mb-3">
                                                            <label class="form-label">Numero
                                                                do
                                                                Cartão</label>
                                                            <input type="text" id="cardNumber"
                                                                wire:model='cardNumber' name="cardNumber"
                                                                class="form-control" placeholder="xxxx-xxxx-xxxx-xxx"
                                                                required="">
                                                        </div>
                                                    </div>
                                                    <div class="col-md-4">
                                                        <div class="mb-3">
                                                            <label class="form-label">Vencimento</label>
                                                            <input type="text" id="expireDate"
                                                                wire:model='expireDate' name="expireDate"
                                                                class="form-control" placeholder="M/Y">
                                                        </div>
                                                    </div>
                                                    <div class="col-md-4">
                                                        <div class="mb-3">
                                                            <label class="form-label">CVV</label>
                                                            <input type="text" id="cvv" wire:model='cvv'
                                                                name="cvv" class="form-control"
                                                                placeholder="xxx">
                                                        </div>
                                                    </div>
                                                    <div class="col-md-4">
                                                        <div class="mb-3">
                                                            <label class="form-label">Parcelas</label>
                                                            <select class="form-control default-select"
                                                                name="parcelas" wire:model.live='parcelas' required>
                                                                @php
                                                                    $juros =
                                                                        ($valor_servico * $settings->juros_parcela) /
                                                                        100;
                                                                    $valor_servico_parcela = $valor_servico + $juros;
                                                                @endphp
                                                                <option value="1">
                                                                    1 x R$
                                                                    {{ number_format($valor_servico, 2, ',', '.') }}
                                                                </option>
                                                                <option value="2">
                                                                    2 x R$
                                                                    {{ number_format($valor_servico_parcela / 2, 2, ',', '.') }}
                                                                </option>
                                                                <option value="3">
                                                                    3 x R$
                                                                    {{ number_format($valor_servico_parcela / 3, 2, ',', '.') }}
                                                                </option>
                                                                <option value="4">
                                                                    4 x R$
                                                                    {{ number_format($valor_servico_parcela / 4, 2, ',', '.') }}
                                                                </option>
                                                                <option value="5">
                                                                    5 x R$
                                                                    {{ number_format($valor_servico_parcela / 5, 2, ',', '.') }}
                                                                </option>
                                                                <option value="6">
                                                                    6 x R$
                                                                    {{ number_format($valor_servico_parcela / 6, 2, ',', '.') }}
                                                                </option>
                                                                <option value="7">
                                                                    7 x R$
                                                                    {{ number_format($valor_servico_parcela / 7, 2, ',', '.') }}
                                                                </option>
                                                                <option value="8">
                                                                    8 x R$
                                                                    {{ number_format($valor_servico_parcela / 8, 2, ',', '.') }}
                                                                </option>
                                                                <option value="9">
                                                                    9 x R$
                                                                    {{ number_format($valor_servico_parcela / 9, 2, ',', '.') }}
                                                                </option>
                                                                <option value="10">
                                                                    10 x R$
                                                                    {{ number_format($valor_servico_parcela / 10, 2, ',', '.') }}
                                                                </option>
                                                                <option value="11">
                                                                    11 x R$
                                                                    {{ number_format($valor_servico_parcela / 11, 2, ',', '.') }}
                                                                </option>
                                                                <option value="12">
                                                                    12 x R$
                                                                    {{ number_format($valor_servico_parcela / 12, 2, ',', '.') }}
                                                                </option>
                                                            </select>
                                                        </div>
                                                    </div>
                                                    <div class="col-12">
                                                        <div class="text-end">
                                                            <button type="submit" class="btn btn-primary">Efetuar
                                                                Pagamento</button>
                                                        </div>
                                                    </div>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="tab-pane fade {{ $currentTab === 'boleto' ? 'show active' : '' }}"
                                id="boleto-tab-pane" role="tabpanel" tabindex="-1">
                                <div class="shadow-none card">
                                    <div class="select-content card-body">
                                        <div>
                                            <div class="text-center settings-form">
                                                <h6 class="mb-2"> Em
                                                    Breve</h6>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div> --}}
                        @else
                            <div class="card">
                                <div class="card-body">
                                    <div class="alert-light-border-success alert" role="alert">
                                        <h4 class="alert-heading"><i
                                                class="ti ti-garden-cart label-icon label-icon-success"></i>
                                            Atenção!</h4>
                                        <p>Seu Pagamento foi realizado com Sucesso, clique
                                            em
                                            próximo para acompanhar o andamento do processo.
                                        </p>
                                    </div>
                                </div>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card">
                <div class="card-header">
                    <h5>Detalhes do Pagamento</h5>
                </div>
                <div class="card-body">
                    <div class="pricing-details">
                        <table class="table mb-0">
                            <thead>
                                <tr>
                                    <th scope="col">Nome</th>
                                    <th scope="col" class="text-end">Descrição</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td>Serviço</td>
                                    <td class="text-end">
                                        {{ Str::title(str_replace('_', ' ', $payment->tipo)) }}
                                        @if ($payment->tipo == 'Cliente_lista')
                                            - Limpa Nome
                                        @endif
                                    </td>
                                </tr>
                                <tr>
                                    @if ($payment->tipo == 'Cliente_lista')
                                        <td>Quant. Clientes</td>
                                        <td class="text-end">{{ $payment->clients->count() }}</td>
                                    @else
                                        <td>Quantidade</td>
                                        <td class="text-end">1</td>
                                    @endif
                                </tr>
                                @if ($payment->tipo == 'recarga')
                                    <tr>
                                        <td>Créditos em Tokens</td>
                                        <td class="text-end">
                                            <small class="text-info">
                                                <i class="ti ti-info-circle"></i>
                                                {{ number_format($payment->valor / ($settings->token_value ?? 0.09), 2, '.', '') }}
                                                créditos
                                            </small>
                                        </td>
                                    </tr>
                                @endif
                            </tbody>
                        </table>
                        <table class="table mb-0">
                            <thead>
                                <tr>
                                    <th>Total</th>
                                    <th class="text-end">{{ number_format($valor_servico, 2, ',', '.') }}</th>
                                </tr>
                            </thead>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

@section('scripts')
    <script src="/assets/js/form_wizard_2.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <!-- Importação do Inputmask -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery.inputmask/5.0.7/jquery.inputmask.min.js"></script>
    <script>
        function applyInputMasks() {
            const component = Livewire.first();
            if ($('#cardNumber').length) {
                $('#cardNumber').inputmask("9999 9999 9999 9999", {
                    placeholder: "____ ____ ____ ____"
                });
                $('#cardNumber').on('blur', function() {
                    let value = $(this).val();
                    if (component) {
                        component.set('cardNumber', value);
                    }
                });
            }
            if ($('#expireDate').length) {
                $('#expireDate').inputmask("99/99", {
                    placeholder: "MM/AA"
                });
                $('#expireDate').on('blur', function() {
                    let value = $(this).val();
                    if (component) {
                        component.set('expireDate', value);
                    }
                });
            }
            if ($('#cvv').length) {
                $('#cvv').inputmask("999[9]");
                $('#cvv').on('blur', function() {
                    let value = $(this).val();
                    if (component) {
                        component.set('cvv', value);
                    }
                });
            }
        }
        // Inicial na primeira carga
        document.addEventListener('DOMContentLoaded', applyInputMasks);
    </script>
    <script>
        $(document).ready(function() {
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

            window.click_copyCode = async function() {
                var copyText = document.getElementById("copy");
                console.log(copyText);
                /* Prevent iOS keyboard from opening */
                copyText.readOnly = true;
                /* Change the input's type to text so its text becomes selectable */
                copyText.type = 'text';
                copyText.select();
                document.execCommand("copy");
                Toast.fire({
                    title: 'Código Pix, copiado com sucesso!',
                    icon: 'success',
                    timer: 2000
                });
            }

            window.copyLink = async function() {
                var copyText = document.getElementById("link_pagamento");
                /* Prevent iOS keyboard from opening */
                copyText.readOnly = true;
                /* Change the input's type to text so its text becomes selectable */
                copyText.type = 'text';
                copyText.select();
                document.execCommand("copy");
                Toast.fire({
                    title: 'Link de Pagamento, copiado com sucesso!',
                    icon: 'success',
                    timer: 2000
                });
            }
        })
    </script>
    <script>
        document.addEventListener('livewire:init', function() {
            // Define o tempo para desaparecer (em milissegundos)
            Livewire.hook('request', (event) => {
                setTimeout(() => {
                    // Seleciona todas as mensagens com a classe fade-out
                    const alerts = document.querySelectorAll('.alert');
                    if (alerts.length > 0) {
                        alerts.forEach(alert => {
                            alert.remove();
                        });
                    }
                }, 3000);
            });
        });
    </script>
@endsection
