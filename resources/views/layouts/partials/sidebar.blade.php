<aside class="pe-app-sidebar" id="sidebar">
    <div class="position-relative d-flex align-items-center px-5 pe-app-sidebar-logo">
        <!--begin::Brand Image-->
        <a href="{{ route('dashboard') }}" class="d-flex align-items-end logo-main">
            <img height="55" width="54" class="logo-dark" alt="Dark Logo" src="/assets/images/logo-md.png">
            <img height="55" width="54" class="logo-light" alt="Light Logo"
                src="/assets/images/logo-md-light.png">
            <h3 class="ms-1 mb-15 text-body-emphasis fw-bolder">ToolZapp</h3>
        </a>
        <button type="button" id="sidebarDefaultArrow"
            class="float-end ms-auto p-0 text-body-emphasis btn btn-sm fs-16 d-none icon-hover-btn d-none"><i
                class="ri-arrow-right-line fs-5"></i></button>
        <!--end::Brand Image-->
    </div>
    <nav class="pe-app-sidebar-menu nav nav-pills" data-simplebar id="sidebar-simplebar">
        <div class="d-flex flex-column align-items-start w-100">
            <ul class="pe-main-menu list-unstyled">
                <!-- Main Menu -->
                <li class="pe-menu-title">Main</li>
                @if (auth()->user()->hasPermission('administrador'))
                    <li class="pe-has-sub pe-slide">
                        <a href="{{ route('admin') }}"
                            class="pe-nav-link {{ \Route::currentRouteName() == 'admin' ? 'active' : '' }}">
                            <i class="pe-nav-icon ri-dashboard-line"></i>
                            <span class="pe-nav-content">Painel Admin</span>
                        </a>
                    </li>
                    <li class="pe-menu-title">Administração</li>
                    <li class="pe-has-sub pe-slide">
                        <a href="{{ route('permissions') }}"
                            class="pe-nav-link {{ \Route::currentRouteName() == 'permissions' ? 'active' : '' }}">
                            <i class="pe-nav-icon ri-shield-check-line"></i>
                            <span class="pe-nav-content">Permissões</span>
                        </a>
                    </li>
                    <li class="pe-has-sub pe-slide">
                        <a href="{{ route('roles') }}"
                            class="pe-nav-link {{ \Route::currentRouteName() == 'roles' ? 'active' : '' }}">
                            <i class="pe-nav-icon ri-user-settings-line"></i>
                            <span class="pe-nav-content">Regras</span>
                        </a>
                    </li>
                    <li class="pe-has-sub pe-slide">
                        <a href="{{ route('admin.usuarios') }}"
                            class="pe-nav-link {{ \Route::currentRouteName() == 'admin.usuarios' ? 'active' : '' }}">
                            <i class="pe-nav-icon ri-team-line"></i>
                            <span class="pe-nav-content">Usuários</span>
                        </a>
                    </li>
                    <li class="pe-has-sub pe-slide">
                        <a href="{{ route('admin.comissoes') }}"
                            class="pe-nav-link {{ \Route::currentRouteName() == 'admin.comissoes' ? 'active' : '' }}">
                            <i class="pe-nav-icon ri-money-dollar-circle-line"></i>
                            <span class="pe-nav-content">Comissões</span>
                        </a>
                    </li>
                    <li class="pe-has-sub pe-slide">
                        <a href="{{ route('admin.pagamentos') }}"
                            class="pe-nav-link {{ \Route::currentRouteName() == 'admin.pagamentos' ? 'active' : '' }}">
                            <i class="pe-nav-icon ri-wallet-3-line"></i>
                            <span class="pe-nav-content">Pagamentos</span>
                        </a>
                    </li>
                    <li class="pe-has-sub pe-slide">
                        <a href="{{ route('admin.whatsapp') }}"
                            class="pe-nav-link {{ \Route::currentRouteName() == 'admin.whatsapp' ? 'active' : '' }}">
                            <i class="pe-nav-icon ri-whatsapp-line"></i>
                            <span class="pe-nav-content">WhatsApp</span>
                        </a>
                    </li>
                    <li class="pe-has-sub pe-slide">
                        <a href="{{ route('admin.sms') }}"
                            class="pe-nav-link {{ \Route::currentRouteName() == 'admin.sms' ? 'active' : '' }}">
                            <i class="pe-nav-icon ri-message-2-line"></i>
                            <span class="pe-nav-content">SMS</span>
                        </a>
                    </li>
                @endif
                @if (auth()->user()->hasAnyPermission(['franquia', 'supervisor', 'consultor', 'cliente']))
                    <li class="pe-has-sub pe-slide">
                        <a href="{{ route('dashboard') }}"
                            class="pe-nav-link {{ \Route::currentRouteName() == 'dashboard' ? 'active' : '' }}">
                            <i class="pe-nav-icon ri-dashboard-line"></i>
                            <span class="pe-nav-content">Dashboards</span>
                        </a>
                    </li>
                    <!-- Pages -->
                    <li class="pe-menu-title">Pages</li>
                    <li class="pe-has-sub pe-slide">
                        <a href="{{ route('user.lists') }}"
                            class="pe-nav-link {{ \Route::currentRouteName() == 'user.lists' ? 'active' : '' }}">
                            <i class="pe-nav-icon ri-contacts-book-2-line"></i>
                            <span class="pe-nav-content">Lista de Usuários</span>
                        </a>
                    </li>
                    <li class="pe-has-sub pe-slide">
                        <a href="{{ route('leads.qualification') }}"
                            class="pe-nav-link {{ \Route::currentRouteName() == 'leads.qualification' ? 'active' : '' }}">
                            <i class="pe-nav-icon ri-user-shared-line"></i>
                            <span class="pe-nav-content">Conversões</span>
                        </a>
                    </li>
                    <li class="pe-has-sub pe-slide">
                        @php
                            $isAtendimentosActive = in_array(\Route::currentRouteName(), [
                                'agents.chats',
                                'personal-chat.index',
                            ]);
                        @endphp
                        <a href="#collapseAtendimentos"
                            class="pe-nav-link {{ $isAtendimentosActive ? 'active' : '' }} {{ !$isAtendimentosActive ? 'collapsed' : '' }}"
                            data-bs-toggle="collapse" aria-expanded="{{ $isAtendimentosActive ? 'true' : 'false' }}"
                            aria-controls="collapseAtendimentos">
                            <i class="pe-nav-icon ri-customer-service-line"></i>
                            <span class="pe-nav-content">Atendimentos</span>
                            <i class="pe-nav-arrow ri-arrow-down-s-line"></i>
                        </a>
                        <ul class="collapse pe-slide-menu {{ $isAtendimentosActive ? 'show' : '' }}"
                            id="collapseAtendimentos">
                            <li class="pe-nav-content1 slide">
                                <a href="javascript:void(0)">Atendimentos</a>
                            </li>
                            <li class="pe-slide-item">
                                <a href="{{ route('agents.chats') }}"
                                    class="pe-nav-link {{ \Route::currentRouteName() == 'agents.chats' ? 'active' : '' }}">
                                    Chats de Atendimentos
                                </a>
                            </li>
                            <li class="pe-slide-item">
                                <a href="{{ route('personal-chat.index') }}"
                                    class="pe-nav-link {{ \Route::currentRouteName() == 'personal-chat.index' ? 'active' : '' }}">
                                    Chats Pessoais
                                </a>
                            </li>
                        </ul>
                    </li>
                    <li class="pe-has-sub pe-slide">
                        @php
                            $isWhatsappActive = in_array(\Route::currentRouteName(), ['whatsapp', 'whatsapp.layouts']);
                        @endphp
                        <a href="#collapseWhatsapp"
                            class="pe-nav-link {{ $isWhatsappActive ? 'active' : '' }} {{ !$isWhatsappActive ? 'collapsed' : '' }}"
                            data-bs-toggle="collapse" aria-expanded="{{ $isWhatsappActive ? 'true' : 'false' }}"
                            aria-controls="collapseWhatsapp">
                            <i class="pe-nav-icon ri-whatsapp-line"></i>
                            <span class="pe-nav-content">WhatsApp</span>
                            <i class="pe-nav-arrow ri-arrow-down-s-line"></i>
                        </a>
                        <ul class="collapse pe-slide-menu {{ $isWhatsappActive ? 'show' : '' }}"
                            id="collapseWhatsapp">
                            <li class="pe-nav-content1 slide">
                                <a href="javascript:void(0)">WhatsApp</a>
                            </li>
                            <li class="pe-slide-item">
                                <a href="{{ route('whatsapp') }}"
                                    class="pe-nav-link {{ \Route::currentRouteName() == 'whatsapp' ? 'active' : '' }}">
                                    Instâncias WhatsApp
                                </a>
                            </li>
                            <li class="pe-slide-item">
                                <a href="{{ route('whatsapp.layouts') }}"
                                    class="pe-nav-link {{ \Route::currentRouteName() == 'whatsapp.layouts' ? 'active' : '' }}">
                                    Mensagens Prontas
                                </a>
                            </li>
                        </ul>
                    </li>
                    <li class="pe-has-sub pe-slide">
                        @php $isSmsActive = \Route::currentRouteName() == 'sms.layouts'; @endphp
                        <a href="{{ route('sms.layouts') }}"
                            class="pe-nav-link {{ $isSmsActive ? 'active' : '' }}">
                            <i class="pe-nav-icon ri-message-2-line"></i>
                            <span class="pe-nav-content">SMS</span>
                        </a>
                    </li>
                    <li class="pe-has-sub pe-slide">
                        <a href="{{ route('crm.index') }}"
                            class="pe-nav-link {{ \Route::currentRouteName() == 'crm.index' ? 'active' : '' }}">
                            <i class="pe-nav-icon ri-briefcase-line"></i>
                            <span class="pe-nav-content">Gestão CRM</span>
                        </a>
                    </li>
                    <li class="pe-has-sub pe-slide">
                        @php
                            $isAgentesActive = in_array(\Route::currentRouteName(), ['agentes', 'apis.config']);
                        @endphp
                        <a href="#collapseAgentes"
                            class="pe-nav-link {{ $isAgentesActive ? 'active' : '' }} {{ !$isAgentesActive ? 'collapsed' : '' }}"
                            data-bs-toggle="collapse" aria-expanded="{{ $isAgentesActive ? 'true' : 'false' }}"
                            aria-controls="collapseAgentes">
                            <i class="pe-nav-icon ri-apps-2-line"></i>
                            <span class="pe-nav-content">Agentes</span>
                            <i class="pe-nav-arrow ri-arrow-down-s-line"></i>
                        </a>
                        <ul class="collapse pe-slide-menu {{ $isAgentesActive ? 'show' : '' }}" id="collapseAgentes">
                            <li class="pe-nav-content1 slide">
                                <a href="javascript:void(0)">Agentes</a>
                            </li>
                            <li class="pe-slide-item">
                                <a href="{{ route('agentes') }}"
                                    class="pe-nav-link {{ \Route::currentRouteName() == 'agentes' ? 'active' : '' }}">
                                    Gerenciar Agentes
                                </a>
                            </li>
                            <li class="pe-slide-item">
                                <a href="{{ route('apis.config') }}"
                                    class="pe-nav-link {{ \Route::currentRouteName() == 'apis.config' ? 'active' : '' }}">
                                    Chaves de APIs
                                </a>
                            </li>
                        </ul>
                    </li>
                    <li class="pe-has-sub pe-slide">
                        @php
                            $isFinanceiroActive = in_array(\Route::currentRouteName(), [
                                'extratos',
                                'pagamentos',
                                'mensalidade',
                                'recarga',
                                'saques',
                                'checkout',
                            ]);
                        @endphp
                        <a href="#collapseFinanceiro"
                            class="pe-nav-link {{ $isFinanceiroActive ? 'active' : '' }} {{ !$isFinanceiroActive ? 'collapsed' : '' }}"
                            data-bs-toggle="collapse" aria-expanded="{{ $isFinanceiroActive ? 'true' : 'false' }}"
                            aria-controls="collapseFinanceiro">
                            <i class="pe-nav-icon ri-wallet-3-line"></i>
                            <span class="pe-nav-content">Financeiro</span>
                            <i class="pe-nav-arrow ri-arrow-down-s-line"></i>
                        </a>
                        <ul class="collapse pe-slide-menu {{ $isFinanceiroActive ? 'show' : '' }}"
                            id="collapseFinanceiro">
                            <li class="pe-nav-content1 slide">
                                <a href="javascript:void(0)">Financeiro</a>
                            </li>
                            <li class="pe-slide-item">
                                <a href="{{ route('pagamentos') }}"
                                    class="pe-nav-link {{ \Route::currentRouteName() == 'pagamentos' ? 'active' : '' }}">
                                    Pagamentos
                                </a>
                            </li>
                            <li class="pe-slide-item">
                                <a href="{{ route('mensalidade') }}"
                                    class="pe-nav-link {{ \Route::currentRouteName() == 'mensalidade' ? 'active' : '' }}">
                                    Mensalidade
                                </a>
                            </li>
                            <li class="pe-slide-item">
                                <a href="{{ route('recarga') }}"
                                    class="pe-nav-link {{ \Route::currentRouteName() == 'recarga' ? 'active' : '' }}">
                                    Recarga de Créditos
                                </a>
                            </li>
                            <li class="pe-slide-item">
                                <a href="{{ route('extratos') }}"
                                    class="pe-nav-link {{ \Route::currentRouteName() == 'extratos' ? 'active' : '' }}">
                                    Extratos
                                </a>
                            </li>
                            <li class="pe-slide-item">
                                <a href="{{ route('saques') }}"
                                    class="pe-nav-link {{ \Route::currentRouteName() == 'saques' ? 'active' : '' }}">
                                    Saques
                                </a>
                            </li>
                        </ul>
                    </li>
                @endif
                <li class="pe-menu-title">Planos</li>
                @if (auth()->user()->hasPermission('administrador'))
                    <li class="pe-has-sub pe-slide">
                        <a href="{{ route('admin.planos') }}"
                            class="pe-nav-link {{ \Route::currentRouteName() == 'admin.planos' ? 'active' : '' }}">
                            <i class="pe-nav-icon ri-vip-crown-line"></i>
                            <span class="pe-nav-content">Gerenciar Planos</span>
                        </a>
                    </li>
                @endif
                <li class="pe-has-sub pe-slide">
                    <a href="{{ route('planos.index') }}"
                        class="pe-nav-link {{ \Route::currentRouteName() == 'planos.index' ? 'active' : '' }}">
                        <i class="pe-nav-icon ri-vip-crown-line"></i>
                        <span class="pe-nav-content">Planos</span>
                    </a>
                </li>
            </ul>
            <!-- Widgets -->
            <div class="text-center sidebar-widget">
                <h5>Sistema de Indicação</h5>
                <img width="50%" src="/assets/images/pages/affiliate.png" alt="Widget Image">
                <p class="text-muted fw-semibold">Clicke para obter seu link de Indicação.</p>
                <button class="rounded-pill w-100 btn btn-primary" onclick="click_copyCode()">Indicação</button>
            </div>
        </div>
    </nav>
</aside>

@section('js')
    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
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

            window.click_copyCode = function() {
                const textToCopy = "{{ route('afiliate.system', auth()->user()->uuid) }}";
                navigator.clipboard.writeText(textToCopy).then(() => {
                    Toast.fire({
                        title: 'Link de Referência copiado com sucesso!',
                        icon: 'success',
                        timer: 2000
                    });
                }).catch(err => {
                    console.error('Erro ao copiar:', err);
                    Toast.fire({
                        title: 'Erro ao copiar o link',
                        icon: 'error',
                        timer: 2000
                    });
                });
            }

        })
    </script>
@endsection
