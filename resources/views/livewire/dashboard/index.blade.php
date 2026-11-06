<!-- breadcrumb end -->
<div class="container-fluid">
    {{-- Componente de navegação compatível com o fluxo Volt padrão. --}}
    @livewire('layout.navigation')

    @include('layouts.partials.page-title', [
        'title' => 'Dashboard',
        'breadcrumbItems' => [
            [
                'label' => 'Home',
                'url' => route('dashboard'),
            ],
            [
                'label' => 'Dashboard',
            ],
        ],
    ])
    <div class="size-column row">
        <div class="col-sm-12 col-lg-12">
            <!-- Start::row -->
            <div class="row row-sm banner-img">
                <div class="col-sm-12 col-lg-12 col-xl-12">
                    <div class="overflow-hidden card boost-up-card">
                        <div class="p-4">
                            <div class="boostup-name row">
                                <h6 class="z-1 mb-2 f-w-700 text-white f-28">{{ $user->name }}!</h6>
                                <p class="f-w-500 text-white f-14 col-9 line-clamp">
                                    Verifique suas configurações e a base administrativa de seus agentes e
                                    mensagens realizadas por seus chats de IA.</p>
                                @if (auth()->user()->hasPermission('creditos'))
                                    @php
                                        $planTotalCredits = 0;
                                        $settings = \App\Models\Settings::first();
                                        if ($user->plan) {
                                            // Créditos base do plano
                                            $baseCredits =
                                                ($user->plan->price / ($settings->token_value ?? 0.09));
                                            $planTotalCredits = $baseCredits * (($user->plan->credits ?? 0) / 100);
                                        } else {
                                            $planTotalCredits = $settings->credits_bonus;
                                        }
                                    @endphp
                                    <p class="z-1 mb-2 f-w-700 text-white f-16">Você tem:
                                        <strong>{{ number_format($user->balance(), 0, ',', '.') . ' / ' . number_format($planTotalCredits, 0, ',', '.') }}
                                            Créditos (Total do Plano)</strong>
                                    </p>
                                @endif
                            </div>
                            <div class="img-boostup">
                                <img class="img-boostup-img-1" src="/assets/images/dashboard/boostup1.png"
                                    alt="boostup">
                                <img class="img-boostup-img-2" src="/assets/images/dashboard/boostup2.png"
                                    alt="boostup">
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <!-- End::row -->
            <!-- Start::row -->
            <div class="mb-4 row">
                <div class="ol-md-6 col-xl-3">
                    <div class="row">
                        <div class="col-xl-12 col-12">
                            <div class="card">
                                <div class="card-body">
                                    <div class="d-flex align-items-center justify-content-between">
                                        <div class="d-flex align-items-center">
                                            <div
                                                class="position-relative d-flex align-items-center justify-content-center shadow-lg me-3 border rounded-3 w-50px h-48px text-warning fs-4">
                                                <i class="ri-wallet-3-line"></i>
                                            </div>
                                            <p class="mb-0 fw-semibold">Saldos do Usuário</p>
                                        </div>
                                    </div>
                                    <div class="mt-6 row g-0">
                                        <div class="col-xxl-6">
                                            <div class="pb-4 pb-xxl-0 border-bottom border-bottom-xxl-0 border-end-xxl">
                                                <h3 class="mb-0">R$
                                                    {{ \App\Helpers\Utils::MoedaBr($user->saldo ?? 0) }}
                                                </h3>
                                                <p class="mb-0 fw-semibold">Saldo Disponível</p>
                                            </div>
                                        </div>
                                        <div class="col-xxl-6">
                                            <div class="mb-0 pt-4 pt-xxl-0 text-xxl-end">
                                                <h4 class="mb-0">R$
                                                    {{ \App\Helpers\Utils::MoedaBr($user->Saques()->sum('valor') ?? 0) }}
                                                </h4>
                                                <p class="mb-0">Faturamento</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-xl-12 col-12">
                            <div class="card">
                                <div class="card-body">
                                    <div class="d-flex align-items-center justify-content-between">
                                        <div class="d-flex align-items-center">
                                            <div
                                                class="position-relative d-flex align-items-center justify-content-center shadow-lg me-3 border rounded-3 w-50px h-48px text-warning fs-4">
                                                <i class="ri-shield-check-line"></i>
                                            </div>
                                            <p class="mb-0 fw-semibold">Status | Vencimento</p>
                                        </div>
                                    </div>
                                    <div class="mt-6 row g-0">
                                        <div class="col-xxl-6">
                                            <div class="pb-4 pb-xxl-0 border-bottom border-bottom-xxl-0 border-end-xxl">
                                                <h5 class="mb-0">
                                                    {{ $user->mensalidade() === 'vencido' ? 'Inativo' : 'Ativo' }}
                                                </h5>
                                                <p class="mb-0 fw-semibold">Status do Plano</p>
                                            </div>
                                        </div>
                                        <div class="col-xxl-6">
                                            <div class="mb-0 pt-4 pt-xxl-0 text-xxl-end">
                                                <h5 class="mb-0">
                                                    {{ $user->mensalidade() === 'vencido' ? 'Vencido' : $user->mensalidade() }}
                                                </h5>
                                                <p class="mb-0"> Mensalidade</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="ol-md-6 col-xl-6">
                    <div class="row">
                        <div class="mb-4 col-md-6 col-xl-6">
                            <div class="shadow-none mb-0 border card">
                                <div class="card-body">
                                    <div
                                        class="position-relative d-flex align-items-center justify-content-center bg-primary-subtle rounded-2 w-50px h-50px text-primary fs-4">
                                        <i class="ri-robot-line"></i>
                                    </div>
                                    <h2 class="mt-3 mb-2 fs-24 fw-semibold">
                                        <span class="counter-value"
                                            data-target="{{ \App\Helpers\Utils::FormatNumber($totalAgents) }}">{{ \App\Helpers\Utils::FormatNumber($totalAgents) }}</span>
                                    </h2>
                                    <p class="mb-1 text-truncate fs-16">Total de Agentes</p>
                                    <span class="text-muted">Agentes de IA criados</span>
                                </div>
                            </div>
                        </div>
                        <div class="mb-4 col-md-6 col-xl-6">
                            <div class="shadow-none mb-0 border card">
                                <div class="card-body">
                                    <div
                                        class="position-relative d-flex align-items-center justify-content-center bg-info-subtle rounded-2 w-50px h-50px text-info fs-4">
                                        <i class="ri-hand-heart-line"></i>
                                    </div>
                                    <h2 class="mt-3 mb-2 fs-24 fw-semibold">
                                        <span class="counter-value"
                                            data-target="{{ \App\Helpers\Utils::FormatNumber($totalConversions) }}">{{ \App\Helpers\Utils::FormatNumber($totalConversions) }}</span>
                                    </h2>
                                    <p class="mb-1 text-truncate fs-16">Total de Conversões</p>
                                    <span class="text-muted">Conversões realizadas</span>
                                </div>
                            </div>
                        </div>
                        <div class="mb-4 col-md-6 col-xl-6">
                            <div class="shadow-none mb-0 border card">
                                <div class="card-body">
                                    <div
                                        class="position-relative d-flex align-items-center justify-content-center bg-primary-subtle rounded-2 w-50px h-50px text-primary fs-4">
                                        <i class="ri-message-3-line"></i>
                                    </div>
                                    <h2 class="mt-3 mb-2 fs-24 fw-semibold">
                                        <span class="counter-value"
                                            data-target="{{ \App\Helpers\Utils::FormatNumber($totalChats) }}">{{ \App\Helpers\Utils::FormatNumber($totalChats) }}</span>
                                    </h2>
                                    <p class="mb-1 text-truncate fs-16">Total de Conversas</p>
                                    <span class="text-muted">Conversas iniciadas</span>
                                </div>
                            </div>
                        </div>
                        <div class="mb-4 col-md-6 col-xl-6">
                            <div class="shadow-none mb-0 border card">
                                <div class="card-body">
                                    <div
                                        class="position-relative d-flex align-items-center justify-content-center bg-warning-subtle rounded-2 w-50px h-50px text-warning fs-4">
                                        <i class="ri-chat-1-line"></i>
                                    </div>
                                    <h2 class="mt-3 mb-2 fs-24 fw-semibold">
                                        <span class="counter-value"
                                            data-target="{{ \App\Helpers\Utils::FormatNumber($totalMessages) }}">{{ \App\Helpers\Utils::FormatNumber($totalMessages) }}</span>
                                    </h2>
                                    <p class="mb-1 text-truncate fs-16">Total de Mensagens</p>
                                    <span class="text-muted">Mensagens trocadas</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="ol-md-6 col-xl-3">
                    <div class="row">
                        <div class="col-xl-12 col-12">
                            <div class="card">
                                <div class="card-body">
                                    <div class="d-flex align-items-center justify-content-between">
                                        <div class="d-flex align-items-center">
                                            <div
                                                class="position-relative d-flex align-items-center justify-content-center shadow-lg me-3 border rounded-3 w-50px h-48px text-warning fs-4">
                                                <i class="ri-coins-line"></i>
                                            </div>
                                            <p class="mb-0 fw-semibold">Créditos Consumidos</p>
                                        </div>
                                    </div>
                                    <div class="mt-6 row g-0">
                                        <div class="col-xxl-6">
                                            <div
                                                class="pb-4 pb-xxl-0 border-bottom border-bottom-xxl-0 border-end-xxl">
                                                <h3 class="mb-0">
                                                    {{ \App\Helpers\Utils::FormatNumber($totalOfCredits ?? 0) }}
                                                </h3>
                                                <p class="mb-0 fw-semibold">Total de Tokens</p>
                                            </div>
                                        </div>
                                        <div class="col-xxl-6">
                                            <div class="mb-0 pt-4 pt-xxl-0 text-xxl-end">
                                                <h4 class="mb-0">R$
                                                    {{ \App\Helpers\Utils::MoedaBr($totalOfCost ?? 0) }}</h4>
                                                <p class="mb-0">Total Custo</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-xl-12 col-12">
                            <div class="card">
                                <div class="card-body">
                                    <div class="d-flex align-items-center justify-content-between">
                                        <div class="d-flex align-items-center">
                                            <div
                                                class="position-relative d-flex align-items-center justify-content-center shadow-lg me-3 border rounded-3 w-50px h-48px text-warning fs-4">
                                                <i class="ri-calendar-check-line"></i>
                                            </div>
                                            <p class="mb-0 fw-semibold">Tokens Utilizados</p>
                                        </div>
                                    </div>
                                    <div class="mt-6 row g-0">
                                        <div class="col-xxl-6">
                                            <div
                                                class="pb-4 pb-xxl-0 border-bottom border-bottom-xxl-0 border-end-xxl">
                                                <h3 class="mb-0">
                                                    {{ \App\Helpers\Utils::FormatNumber($creditsToday ?? 0) }}</h3>
                                                <p class="mb-0 fw-semibold">Tokens Hoje</p>
                                            </div>
                                        </div>
                                        <div class="col-xxl-6">
                                            <div class="mb-0 pt-4 pt-xxl-0 text-xxl-end">
                                                <h4 class="mb-0">
                                                    {{ \App\Helpers\Utils::FormatNumber($totalCreditsWeek ?? 0) }}
                                                </h4>
                                                <p class="mb-0">Total semanal</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <!-- End::row -->
        </div><!-- col end -->
    </div>

    <!-- Chart widget top Start-->
    <div class="row">
        <div class="col-xl-4 col-md-12 box-col-12">
            <div class="o-hidden card">
                <div class="chart-widget-top">
                    <div class="m-0 pb-0 row card-body">
                        <div class="p-0 col-xl-9 col-lg-8 col-9">
                            <h6 class="mb-2">Total de Mensagens</h6>
                            <h4>{{ $totalMessages }}</h4><span>Atendimentos na Semana</span>
                        </div>
                    </div>
                    <div>
                        <div id="chart-widget1"></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-4 col-md-12 box-col-12">
            <div class="o-hidden card">
                <div class="chart-widget-top">
                    <div class="m-0 pb-0 row card-body">
                        <div class="p-0 col-xl-9 col-lg-8 col-9">
                            <h6 class="mb-2">Total de Atendimentos</h6>
                            <h4>{{ $totalMessagesMonth }}</h4><span>Atendimentos no Mês</span>
                        </div>
                    </div>
                    <div id="chart-widget2">
                        <div class="flot-chart-placeholder" id="chart-widget-top-second"></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-4 col-md-12 box-col-12">
            <div class="o-hidden card">
                <div class="chart-widget-top">
                    <div class="m-0 pb-0 row card-body">
                        <div class="p-0 col-xl-9 col-lg-8 col-9">
                            <h6 class="mb-2">Total De Conversão</h6>
                            <h4>{{ $totalConversionMonth }}</h4><span>Total de conversões no mês</span>
                        </div>
                    </div>
                    <div id="chart-widget3">
                        <div class="flot-chart-placeholder" id="chart-widget-top-third"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <!-- Chart widget top Ends-->
</div>

@push('styles')
    <style>
        .boost-up-card {
            background: radial-gradient(circle, rgb(110 167 215) 2%, rgb(23 108 181) 50%, rgb(4 49 88) 100%);
        }

        .img-boostup {
            position: absolute;
            top: 0px;
            right: 237px;
        }

        .img-boostup .img-boostup-img-1 {
            left: -40px;
            top: -105px;
            position: absolute;
            height: 200px;
        }

        .img-boostup .img-boostup-img-2 {
            position: absolute;
            top: 45px;
            left: -5px;
            height: 130px;
        }
    </style>
@endpush

@push('scripts')
    <script src="/assets/js/chart/apex-chart/apex-chart.js"></script>
    <script src="/assets/js/chart/apex-chart/moment.min.js"></script>
    <script>
        (function($) {
            "use strict";
            /*Line chart*/
            var optionslinechart = {
                chart: {
                    toolbar: {
                        show: false,
                    },
                    height: 200,
                    type: "area",
                },
                dataLabels: {
                    enabled: false,
                },
                stroke: {
                    width: 3,
                    curve: "smooth",
                },
                xaxis: {
                    show: false,
                    type: "text",
                    categories: [
                        "SEGUNDA",
                        "TERÇA",
                        "QUARTA",
                        "QUINTA",
                        "SEXTA",
                        "SABADO",
                        "DOMINGO"
                    ],
                    labels: {
                        show: false,
                    },
                    axisBorder: {
                        show: false,
                    },
                },
                yaxis: {
                    show: false
                },
                grid: {
                    show: false,
                    padding: {
                        left: -5,
                        top: -25,
                        right: -5,
                        bottom: -15,
                    },
                },
                fill: {
                    opacity: 0.2,
                },
                colors: ['#007bff'],
                series: [{
                    name: "Menssagens",
                    data: @json($messages),
                }, ],
                responsive: [{
                    breakpoint: 576,
                    options: {
                        chart: {
                            height: 100,
                        }
                    }
                }]
            };
            var chartlinechart = new ApexCharts(
                document.querySelector("#chart-widget1"),
                optionslinechart
            );
            chartlinechart.render();

            /*Line chart2*/
            var optionslinechart2 = {
                chart: {
                    toolbar: {
                        show: false,
                    },
                    height: 200,
                    type: "area",
                },
                dataLabels: {
                    enabled: false,
                },
                stroke: {
                    width: 3,
                    curve: "smooth",
                },
                xaxis: {
                    show: false,
                    type: "text",
                    categories: @json($labelMonth),
                    labels: {
                        show: false,
                    },
                    axisBorder: {
                        show: false,
                    },
                },
                yaxis: {
                    show: false
                },
                grid: {
                    show: false,
                    padding: {
                        left: -5,
                        top: -25,
                        right: -5,
                        bottom: -15,
                    },
                },
                fill: {
                    opacity: 0.2,
                },
                colors: ['#6c757d'],
                series: [{
                    name: "Atendimentos",
                    data: @json($messagesMonth),
                }, ],
                responsive: [{
                    breakpoint: 576,
                    options: {
                        chart: {
                            height: 100,
                        }
                    }
                }]
            };
            var chartlinechart2 = new ApexCharts(
                document.querySelector("#chart-widget2"),
                optionslinechart2
            );
            chartlinechart2.render();

            /*Line chart3*/
            var optionslinechart3 = {
                chart: {
                    toolbar: {
                        show: false,
                    },
                    height: 200,
                    type: "area",
                },
                dataLabels: {
                    enabled: false,
                },
                stroke: {
                    width: 3,
                    curve: "smooth",
                },
                xaxis: {
                    show: false,
                    type: "text",
                    categories: @json($labelMonth),
                    labels: {
                        show: false,
                    },
                    axisBorder: {
                        show: false,
                    },
                },
                yaxis: {
                    show: false
                },
                grid: {
                    show: false,
                    padding: {
                        left: -5,
                        top: -25,
                        right: -5,
                        bottom: -15,
                    },
                },
                fill: {
                    opacity: 0.2,
                },
                colors: ["#51bb25"],
                series: [{
                    name: "Conversões",
                    data: @json($userConvertion),
                }, ],
                responsive: [{
                    breakpoint: 576,
                    options: {
                        chart: {
                            height: 100,
                        }
                    }
                }]
            };
            var chartlinechart3 = new ApexCharts(
                document.querySelector("#chart-widget3"),
                optionslinechart3
            );
            chartlinechart3.render();

            // Total-Revenue-chart
            var optionsMonth = {
                series: [{
                    name: 'Créditos',
                    data: @json($creditsMonth)
                }],
                colors: ['#007bff', '#E6E9EB'],
                chart: {
                    type: 'bar',
                    height: 120,
                    width: '100%',
                    offsetY: -20,
                    zoom: {
                        enabled: false,
                    },
                    toolbar: {
                        show: false,
                    }
                },
                plotOptions: {
                    bar: {
                        horizontal: false,
                        columnWidth: '100%',
                        endingShape: 'rounded',
                        borderRadius: 1,
                        hideZeroBarsWhenGrouped: false, // Adicione esta linha
                    },
                },
                grid: {
                    show: false,
                    padding: {
                        top: 10,
                        right: 0,
                        bottom: -25,
                        left: 0
                    },
                },
                dataLabels: {
                    enabled: false
                },
                stroke: {
                    show: true,
                    width: 2,
                    colors: ['transparent']
                },
                legend: {
                    show: false,
                },
                xaxis: {
                    show: false,
                    type: "text",
                    categories: @json($labelMonth),
                    labels: {
                        show: false,
                    },
                    lines: {
                        show: false,
                    },
                    axisTicks: {
                        show: false,
                    },
                },
                yaxis: {
                    show: false,
                    min: -1, // força barra zero aparecer
                    lines: {
                        show: false,
                    },
                    forceNiceScale: false, // Impede que o gráfico ajuste automaticamente a escala
                },
                fill: {
                    opacity: 1
                },
                responsive: [{
                    breakpoint: 1599,
                    options: {
                        chart: {
                            width: '100%'
                        },
                    }
                }],
            };
            var revenue = new ApexCharts(document.querySelector("#revenue"), optionsMonth);
            revenue.render();

            var optionsWeek = {
                series: [{
                    name: 'Créditos',
                    data: @json($creditsWeek)
                }],
                colors: ['#007bff', '#E6E9EB'],
                chart: {
                    type: 'bar',
                    height: 120,
                    width: '100%',
                    offsetY: -20,
                    zoom: {
                        enabled: false,
                    },
                    toolbar: {
                        show: false,
                    }
                },
                plotOptions: {
                    bar: {
                        horizontal: false,
                        columnWidth: '100%',
                        endingShape: 'rounded',
                        borderRadius: 1,
                        hideZeroBarsWhenGrouped: false, // Adicione esta linha
                    },
                },
                grid: {
                    show: false,
                    padding: {
                        top: 10,
                        right: 0,
                        bottom: -25,
                        left: 0
                    },
                },
                dataLabels: {
                    enabled: false
                },
                stroke: {
                    show: true,
                    width: 2,
                    colors: ['transparent']
                },
                legend: {
                    show: false,
                },
                xaxis: {
                    show: false,
                    type: "text",
                    categories: [
                        "SEGUNDA",
                        "TERÇA",
                        "QUARTA",
                        "QUINTA",
                        "SEXTA",
                        "SABADO",
                        "DOMINGO"
                    ],
                    labels: {
                        show: false,
                    },
                    lines: {
                        show: false,
                    },
                    axisTicks: {
                        show: false,
                    },
                },
                yaxis: {
                    show: false,
                    min: -1, // força barra zero aparecer
                    lines: {
                        show: false,
                    },
                    forceNiceScale: false, // Impede que o gráfico ajuste automaticamente a escala
                },
                fill: {
                    opacity: 1
                },
                responsive: [{
                    breakpoint: 1599,
                    options: {
                        chart: {
                            width: '100%'
                        },
                    }
                }],
            };
            var revenue2 = new ApexCharts(document.querySelector("#revenue2"), optionsWeek);
            revenue2.render();
        })(jQuery);
    </script>
@endpush
