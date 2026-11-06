<div class="container-fluid" {{ !$isActive ? 'wire:poll.30s=checkPaymentStatus' : null }}>
    @include('layouts.partials.page-title', [
        'title' => 'Mensalidades',
        'breadcrumbItems' => [['label' => 'Home', 'url' => route('dashboard')], ['label' => 'Mensalidades']],
    ])
    <div class="row">
        <div style="margin-top: 0.2rem">
            @include('layouts.includes.errors-and-messages')
        </div>
        <div class="col-lg-12">
            <div class="card">
                <div class="card-header"> Pagamento de Mensalidade
                    <strong>{{ $venc_mensalid }}. </strong><br>
                    <span class="float-right">
                        <strong>Status:</strong> {{ Str::upper($status ?? 'INATIVO') }}</span>
                </div>
                <div class="card-body">
                    <div class="mb-5 row">
                        <div class="mt-4 col-xl-3 col-lg-3 col-md-6 col-sm-12">
                            <h6>Dados do Cliente:</h6>
                            <div> <strong>{{ $user->name }}</strong> </div>
                            <div><strong>Email:</strong> {{ $user->email }}</div>
                            <div><strong>Phone:</strong> {{ $user->phone }}</div>
                            <div><strong>CPF:</strong> {{ $user->cpf }}</div>
                        </div>
                        <div class="mt-4 col-xl-3 col-lg-3 col-md-12 col-sm-12">
                            <h6>Detalhes do Pagamento:</h6>
                            <div> <strong>Tipo de Serviço:</strong> {{ $user->cargo }} </div>
                            <div> <strong>Valor:</strong> R$ {{ App\Helpers\Utils::MoedaBr($valor) }} </div>
                            <div> <strong>Vencimento:</strong> {{ $vencimento }} </div>

                            @if ($pagamentos->isEmpty())
                                <div class="mt-2 alert alert-warning">
                                    <small><strong>Atenção:</strong> Você não possui nenhum plano ativo. Para fazer
                                        pagamentos de mensalidade, primeiro ative um plano.</small>
                                </div>
                                <div class="mt-3">
                                    <a href="{{ route('planos.index') }}" class="btn-block btn btn-primary">
                                        <i class="ti ti-star"></i> Ativar Plano
                                    </a>
                                </div>
                            @else
                                @if (isset($status) && $status === 'inativo')
                                    <div class="mt-2 alert alert-info">
                                        <small><strong>Primeiro pagamento:</strong> Ative seu plano pagando o valor
                                            acima.</small>
                                    </div>
                                @elseif(isset($status) && $status === 'vencido')
                                    <div class="mt-2 alert alert-warning">
                                        <small><strong>Renovação necessária:</strong> Seu plano venceu. Renove pagando o
                                            valor do último plano.</small>
                                    </div>
                                @elseif(isset($status) && $status === 'ativo')
                                    <div class="mt-2 alert alert-success">
                                        <small><strong>Plano ativo:</strong> Seu plano está em dia.</small>
                                    </div>
                                @endif
                                <div>
                                    <div class="form-group">
                                        <label for="mensalidade">Digite quantas Mensalidades</label>
                                        <input type="number" class="form-control" min="1" max="12"
                                            wire:model.live="mensalidade" style="border: 1px solid #097a73;"
                                            id="mensalidade" {{ $status === 'ativo' ? 'disabled' : '' }}>
                                    </div>
                                </div>
                            @endif
                        </div>
                        <div class="col-xl-6 col-lg-6 col-md-12 col-sm-12">
                            @if ($pagamentos->isEmpty())
                                <div class="card">
                                    <div class="card-header">
                                        <h5>Ative seu Plano Primeiro</h5>
                                    </div>
                                    <div class="text-center card-body">
                                        <div class="alert alert-info">
                                            <h6><i class="ti ti-info-circle"></i> Plano Necessário</h6>
                                            <p>Para fazer pagamentos de mensalidade, você precisa primeiro ativar um
                                                plano.</p>
                                        </div>
                                    </div>
                                </div>
                            @else
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
                                            <button class="flex-grow-1 text-center btn nav-link" id="profile-tab"
                                                data-bs-toggle="tab" data-bs-target="#cred-card-tab-pane" type="button"
                                                role="tab" aria-controls="cred-card-tab-pane" aria-selected="false">
                                                <span class="d-flex align-items-center mt-1">
                                                    <span> <i class="pe-2 ti ti-file-dollar"></i></span>
                                                    <span class="d-flex flex-column ms-3 text-start custom-title">
                                                        <span class="f-w-500 f-s-16">Cartão de Crédito</span>
                                                    </span>
                                                </span>
                                            </button>
                                        </div>
                                        <div class="mt-3 tab-content" id="justify-LightContent">
                                            <div class="tab-pane fade show active" id="pix-tab-pane" role="tabpanel"
                                                aria-labelledby="account-tab" tabindex="-1">
                                                @if ($paymentOpenPix)
                                                    <div class="settings-form">
                                                        <h5 class="mb-4 text-primary">
                                                            Método de Pagamento
                                                            <button type="button" class="btn btn-danger btn-xs"
                                                                wire:click="cancelPayment">
                                                                Cancelar
                                                            </button>
                                                        </h5>

                                                        @php
                                                            $qrCodeSrc = $paymentOpenPix->qr_code ?? null;
                                                            if (
                                                                empty($qrCodeSrc) &&
                                                                ($paymentOpenPix->pix_code ?? null)
                                                            ) {
                                                                $qrCodeSrc = \App\Helpers\Utils::generateQrCodeBase64(
                                                                    $paymentOpenPix->pix_code,
                                                                );
                                                            }
                                                            if (
                                                                $qrCodeSrc &&
                                                                !\Illuminate\Support\Str::startsWith(
                                                                    $qrCodeSrc,
                                                                    'data:',
                                                                )
                                                            ) {
                                                                $qrCodeSrc = 'data:image/png;base64,' . $qrCodeSrc;
                                                            }
                                                        @endphp
                                                        @if ($qrCodeSrc)
                                                            <img width="250" id="qrCodeImage"
                                                                src="{{ $qrCodeSrc }}" alt="QR Code PIX">
                                                        @endif
                                                        <p>ou copie a chave PIX:
                                                            <strong>{{ Str::limit($paymentOpenPix->pix_code, 15, '...') }}</strong>
                                                        </p>
                                                        <input type="hidden" id="copy"
                                                            value="{{ $paymentOpenPix->pix_code }}" readonly>
                                                        <button class="btn btn-primary btn-xs"
                                                            onclick="click_copyCode()">Copiar
                                                            Código</button>
                                                        <button class="ms-2 btn btn-success btn-xs"
                                                            wire:click="checkPixPaymentStatus">Verificar
                                                            Pagamento</button>
                                                        <p>Aguardando pagamento...</p>
                                                    </div>
                                                @else
                                                    <section class="panel">
                                                        <div class="p-5 text-center overlay-box">
                                                            <h4 class="mt-3 mb-0 text-primary">Gerar novo
                                                                QRCODE</h4>
                                                        </div>
                                                        <div class="panel-body">
                                                            <center><button type="submit" class="btn btn-primary"
                                                                    wire:click="generatePayment">Gerar
                                                                    QRCODE</button></center>
                                                        </div>
                                                    </section>
                                                @endif
                                            </div>

                                            <div class="tab-pane fade" id="cred-card-tab-pane" role="tabpanel"
                                                aria-labelledby="review-tab" tabindex="-1">
                                                <div class="shadow-none card">
                                                    <div class="select-content card-body">
                                                        <div class="mb-3">
                                                            <label class="check-box">
                                                                <span class="fs-6 tab-heading">Cartão
                                                                    de Crédito</span>
                                                            </label>
                                                        </div>
                                                        <div>
                                                            <form class="app-form" method="post" action="#">
                                                                @csrf
                                                                <div class="row">
                                                                    <div class="col-12">
                                                                        <div class="mb-3">
                                                                            <label class="form-label">Nome
                                                                                Completo</label>
                                                                            <input type="text" class="form-control"
                                                                                name="name"
                                                                                placeholder="Digite o Nome igual ao do cartão">
                                                                        </div>
                                                                    </div>
                                                                    <div class="col-12">
                                                                        <div class="mb-3">
                                                                            <label class="form-label">Numero
                                                                                do
                                                                                Cartão</label>
                                                                            <input type="text" id="cardNumber"
                                                                                name="cardNumber" class="form-control"
                                                                                placeholder="xxxx-xxxx-xxxx-xxx"
                                                                                required="">
                                                                        </div>
                                                                    </div>
                                                                    <div class="col-md-4">
                                                                        <div class="mb-3">
                                                                            <label
                                                                                class="form-label">Vencimento</label>
                                                                            <input type="text" id="expireDate"
                                                                                name="expireDate" class="form-control"
                                                                                placeholder="M/Y">
                                                                        </div>
                                                                    </div>
                                                                    <div class="col-md-4">
                                                                        <div class="mb-3">
                                                                            <label class="form-label">CVV</label>
                                                                            <input type="text" id="cvv"
                                                                                name="cvv" class="form-control"
                                                                                placeholder="xxx">
                                                                        </div>
                                                                    </div>
                                                                    <div class="col-12">
                                                                        <div class="text-end">
                                                                            <button type="button"
                                                                                class="btn btn-primary">Efetuar
                                                                                Pagamento</button>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            </form>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

@section('css')
    <!-- flatpickr css -->
    <link rel="stylesheet" type="text/css" href="{{ asset('assets/vendor/datepikar/flatpickr.min.css') }}">
    <!-- Data Table css-->
    <link rel="stylesheet" type="text/css" href="{{ asset('assets/vendor/datatable/jquery.dataTables.min.css') }}">
    <link rel="stylesheet" type="text/css"
        href="{{ asset('assets/vendor/datatable/datatable2/buttons.dataTables.min.css') }}">

    <style>
        /* Garantir que as setinhas do input number apareçam */
        #mensalidade::-webkit-outer-spin-button,
        #mensalidade::-webkit-inner-spin-button {
            -webkit-appearance: inner-spin-button !important;
            opacity: 1 !important;
            height: 100% !important;
            width: 20px !important;
        }

        /* Para Firefox - remover o textfield para mostrar as setinhas */
        #mensalidade {
            -moz-appearance: number-input !important;
            height: 38px !important;
            padding-right: 30px !important;
        }

        /* Garantir que o input tenha espaço para as setinhas */
        .form-control[type="number"] {
            padding-right: 30px !important;
        }
    </style>
@endsection

@section('scripts')
    <!-- checkout js -->
    <script src="{{ asset('/assets/js/checkout.js') }}"></script>
    <script src="{{ asset('assets/vendor/datepikar/flatpickr.js') }}"></script>
    <script src="{{ asset('assets/js/date_picker.js') }}"></script>
    <!-- filepond -->
    <script src="{{ asset('assets/vendor/filepond/file-encode.min.js') }}"></script>
    <script src="{{ asset('assets/vendor/filepond/validate-size.min.js') }}"></script>
    <script src="{{ asset('assets/vendor/filepond/validate-type.js') }}"></script>
    <script src="{{ asset('assets/vendor/filepond/exif-orientation.min.js') }}"></script>
    <script src="{{ asset('assets/vendor/filepond/image-preview.min.js') }}"></script>
    <script src="{{ asset('assets/vendor/filepond/filepond.min.js') }}"></script>

    <!-- phosphor js -->
    <script src="{{ asset('assets/vendor/phosphor/phosphor.js') }}"></script>

    <!-- js -->
    <script src="{{ asset('assets/js/file_upload.js') }}"></script>
    <script src="{{ asset('assets/js/form_wizard_2.js') }}"></script>
    <!-- datatable js -->
    <script src="{{ asset('assets/vendor/datatable/jquery.dataTables.min.js') }}"></script>
    <!-- js-->
    <script src="{{ asset('/assets/js/data_table.js') }}"></script>
    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        document.addEventListener('livewire:init', function() {
            Livewire.on('resetTable', (event) => {
                setTimeout(() => {
                    initDataTable();
                }, 300); // Pequeno delay para garantir que a tabela foi carregada no DOM
            });
            // Escuta quando o Livewire re-renderiza a tabela e reinicia o DataTable
            Livewire.hook('request', (event) => {
                setTimeout(() => {
                    initDataTable();
                }, 300); // Pequeno delay para garantir que a tabela foi carregada no DOM
            });
        });
    </script>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <!-- Importação do Inputmask -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery.inputmask/5.0.7/jquery.inputmask.min.js"></script>

    <script>
        $(document).ready(function() {
            // Aplicar máscara no número do cartão
            $('input[name="cardNumber"]').inputmask("9999 9999 9999 9999", {
                placeholder: "____ ____ ____ ____"
            });

            // Aplicar máscara no MM/AA
            $('input[name="expireDate"]').inputmask("99/99", {
                placeholder: "MM/AA"
            });

            // Aplicar máscara no CVV (3 ou 4 dígitos)
            $('input[name="cvv"]').inputmask("999[9]");
        });
    </script>

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
        })
    </script>
@endsection
