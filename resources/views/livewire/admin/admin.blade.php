<!-- breadcrumb end -->
<div class="container-fluid">
    @include('layouts.partials.page-title', [
        'title' => 'Gerenciamento de Usuários',
        'breadcrumbItems' => [
            [
                'label' => 'Home',
                'url' => route('dashboard'),
            ],
            [
                'label' => 'Administração',
            ],
        ],
    ])
    <!--Row-->

    <!-- Start::row -->
    <div class="mb-4 row">
        <div style="margin-top: 0.2rem">
            @include('layouts.includes.errors-and-messages')
        </div>
        <div class="col-xl-3 col-sm-6">
            <div class="card-h-100 card">
                <div class="d-flex align-items-center justify-content-around card-body">
                    <div
                        class="position-relative d-flex align-items-center justify-content-center shadow-lg border rounded-3 w-50px h-48px text-primary fs-4">
                        <i class="ri-user-line"></i>
                    </div>
                    <div>
                        <h3>{{ $totalUsers }} <i class="text-success bi bi-graph-up-arrow fw-normal fs-5"></i></h3>
                        <span class="fs-5">Total de Usuários</span>
                        <p class="mb-0 fs-12">Usuários cadastrados</p>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-sm-6">
            <div class="card-h-100 card">
                <div class="d-flex align-items-center justify-content-around card-body">
                    <div
                        class="position-relative d-flex align-items-center justify-content-center shadow-lg border rounded-3 w-50px h-48px text-success fs-4">
                        <i class="ri-user-follow-line"></i>
                    </div>
                    <div>
                        <h3>{{ $activeUsers }} <i class="text-success bi bi-graph-up-arrow fw-normal fs-5"></i></h3>
                        <span class="fs-5">Usuários Ativos</span>
                        <p class="mb-0 fs-12">Ativos no sistema</p>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-sm-6">
            <div class="card-h-100 card">
                <div class="d-flex align-items-center justify-content-around card-body">
                    <div
                        class="position-relative d-flex align-items-center justify-content-center shadow-lg border rounded-3 w-50px h-48px text-secondary fs-4">
                        <i class="ri-user-unfollow-line"></i>
                    </div>
                    <div>
                        <h3>{{ $inactiveUsers }} <i class="text-danger bi bi-graph-down-arrow fw-normal fs-5"></i></h3>
                        <span class="fs-5">Usuários Inativos</span>
                        <p class="mb-0 fs-12">Inativos no sistema</p>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-sm-6">
            <div class="card-h-100 card">
                <div class="d-flex align-items-center justify-content-around card-body">
                    <div
                        class="position-relative d-flex align-items-center justify-content-center shadow-lg border rounded-3 w-50px h-48px text-warning fs-4">
                        <i class="ri-time-line"></i>
                    </div>
                    <div>
                        <h3>{{ $pindingLog }} <i class="text-warning bi bi-clock fw-normal fs-5"></i></h3>
                        <span class="fs-5">Ativações Pendentes</span>
                        <p class="mb-0 fs-12">Aguardando aprovação</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <!-- End::row -->

    <!-- Start::row - Créditos -->
    <div class="mb-4 row">
        <div class="col-md-6 col-xl-3">
            <div class="shadow-none mb-0 border card">
                <div class="card-body">
                    <div
                        class="position-relative d-flex align-items-center justify-content-center bg-primary-subtle rounded-2 w-50px h-50px text-primary fs-4">
                        <i class="ri-pulse-line"></i>
                    </div>
                    <h2 class="mt-3 mb-2 fs-24 fw-semibold">
                        <span class="counter-value" data-target="{{ $totalOfCredits }}">{{ $totalOfCredits }}</span>
                    </h2>
                    <p class="mb-1 text-truncate fs-16">Total Créditos</p>
                    <span class="text-muted">Créditos consumidos</span>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-xl-3">
            <div class="shadow-none mb-0 border card">
                <div class="card-body">
                    <div
                        class="position-relative d-flex align-items-center justify-content-center bg-warning-subtle rounded-2 w-50px h-50px text-warning fs-4">
                        <i class="ri-money-dollar-circle-line"></i>
                    </div>
                    <h2 class="mt-3 mb-2 fs-24 fw-semibold">
                        R$ <span class="counter-value" data-target="{{ $totalOfCost }}">{{ $totalOfCost }}</span>
                    </h2>
                    <p class="mb-1 text-truncate fs-16">Total Custo</p>
                    <span class="text-muted">Valor total utilizado</span>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-xl-3">
            <div class="shadow-none mb-0 border card">
                <div class="card-body">
                    <div
                        class="position-relative d-flex align-items-center justify-content-center bg-info-subtle rounded-2 w-50px h-50px text-info fs-4">
                        <i class="ri-calendar-line"></i>
                    </div>
                    <h2 class="mt-3 mb-2 fs-24 fw-semibold">
                        <span class="counter-value" data-target="{{ $creditsToday }}">{{ $creditsToday }}</span>
                    </h2>
                    <p class="mb-1 text-truncate fs-16">Créditos Hoje</p>
                    <span class="text-muted">Créditos do dia</span>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-xl-3">
            <div class="shadow-none mb-0 border card">
                <div class="card-body">
                    <div
                        class="position-relative d-flex align-items-center justify-content-center bg-success-subtle rounded-2 w-50px h-50px text-success fs-4">
                        <i class="ri-table-line"></i>
                    </div>
                    <h2 class="mt-3 mb-2 fs-24 fw-semibold">
                        <span class="counter-value"
                            data-target="{{ $totalCreditsWeek }}">{{ $totalCreditsWeek }}</span>
                    </h2>
                    <p class="mb-1 text-truncate fs-16">Créditos na Semana</p>
                    <span class="text-muted">Total semanal</span>
                </div>
            </div>
        </div>
    </div>
    <!-- End::row -->

    <!-- Start::row - Agentes -->
    <div class="row row-sm">
        <div class="col-md-12">
            <div class="card custom-card">
                <div class="d-flex align-items-center justify-content-between pb-0 card-header">
                    <h5 class="mb-0">Gerenciamento de Agentes</h5>
                </div>
                <div class="card-body">
                    <div class="row gy-4">
                        <div class="col-md-6 col-xl-3">
                            <div class="shadow-none mb-0 border card">
                                <div class="text-center card-body">
                                    <div class="position-relative d-flex align-items-center justify-content-center bg-primary-subtle mx-auto mb-3 rounded-circle"
                                        style="width: 60px; height: 60px;">
                                        <i class="text-primary ri-robot-line fs-3"></i>
                                    </div>
                                    <h3 class="mb-1">{{ $totalAgents ?? 0 }}</h3>
                                    <p class="mb-0 text-muted">Total de Agentes</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6 col-xl-3">
                            <div class="shadow-none mb-0 border card">
                                <div class="text-center card-body">
                                    <div class="position-relative d-flex align-items-center justify-content-center bg-warning-subtle mx-auto mb-3 rounded-circle"
                                        style="width: 60px; height: 60px;">
                                        <i class="text-warning ri-message-3-line fs-3"></i>
                                    </div>
                                    <h3 class="mb-1">{{ $totalChats ?? 0 }}</h3>
                                    <p class="mb-0 text-muted">Total de Chats</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6 col-xl-3">
                            <div class="shadow-none mb-0 border card">
                                <div class="text-center card-body">
                                    <div class="position-relative d-flex align-items-center justify-content-center bg-secondary-subtle mx-auto mb-3 rounded-circle"
                                        style="width: 60px; height: 60px;">
                                        <i class="text-secondary ri-chat-4-line fs-3"></i>
                                    </div>
                                    <h3 class="mb-1">{{ $totalMessages ?? 0 }}</h3>
                                    <p class="mb-0 text-muted">Total de Mensagens</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6 col-xl-3">
                            <div class="shadow-none mb-0 border card">
                                <div class="text-center card-body">
                                    <div class="position-relative d-flex align-items-center justify-content-center bg-success-subtle mx-auto mb-3 rounded-circle"
                                        style="width: 60px; height: 60px;">
                                        <i class="text-success ri-check-double-line fs-3"></i>
                                    </div>
                                    <h3 class="mb-1">{{ $user->convertedChats->count() ?? 0 }}</h3>
                                    <p class="mb-0 text-muted">Total de Conversões</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <!-- Row end -->
</div>

@push('scripts')
    <script src="/assets/js/chart/apex-chart/apex-chart.js"></script>
    <script src="/assets/js/chart/apex-chart/moment.min.js"></script>
    <script>
        (function($) {
            "use strict";
            // Total-Revenue-chart
            var optionsMonth = {
                series: [{
                    name: 'Créditos',
                    data: @json($creditsMonth)
                }],
                colors: [RihoAdminConfig.primary, '#E6E9EB'],
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
                colors: [RihoAdminConfig.primary, '#E6E9EB'],
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
