<nav class="sidebar-main">
    <div class="left-arrow" id="left-arrow"><i data-feather="arrow-left"></i></div>
    <div id="sidebar-menu">
        <ul class="sidebar-links" id="simple-bar">
            <li class="back-btn">
                <a href="{{ route('dashboard') }}">
                    <img class="img-fluid" src="/assets/images/logo/logo-icon.png" alt="">
                </a>
                <div class="text-end mobile-back"> <span>Back </span><i class="fa-angle-right ps-2 fa"
                        aria-hidden="true"></i></div>
            </li>
            <li class="pin-title sidebar-main-title">
                <div>
                    <h6>Pinned</h6>
                </div>
            </li>
            <li class="sidebar-main-title">
                <div>
                    <h6 class="lan-1">General</h6>
                </div>
            </li>
            @if (auth()->user()->hasPermission('administrador'))
                <li class="mt-2 sidebar-list">
                    <i class="fa fa-thumb-tack"></i>
                    <a class="sidebar-link sidebar-title link-nav" href="{{ route('admin') }}">
                        <svg class="stroke-icon">
                            <use href="/assets/svg/icon-sprite.svg#stroke-home"></use>
                        </svg>
                        <svg class="fill-icon">
                            <use href="/assets/svg/icon-sprite.svg#fill-home"></use>
                        </svg><span>Painel</span>
                        <div class="according-menu"><i class="fa-angle-right fa"></i></div>
                    </a>
                </li>
                <li class="sidebar-list">
                    <i class="fa fa-thumb-tack"></i>
                    <a class="sidebar-link sidebar-title" href="javascript:void(0)">
                        <svg class="stroke-icon">
                            <use href="/assets/svg/icon-sprite.svg#stroke-animation"></use>
                        </svg>
                        <svg class="fill-icon">
                            <use href="/assets/svg/icon-sprite.svg#fill-animation"></use>
                        </svg><span>Administração</span>
                    </a>
                    <ul class="sidebar-submenu">
                        <li>
                            <a href="{{ route('permissions') }}">Permissões</a>
                        </li>
                        <li>
                            <a href="{{ route('roles') }}">Regras</a>
                        </li>
                        <li>
                            <a href="{{ route('admin.usuarios') }}">Usuários</a>
                        </li>
                        <li>
                            <a href="{{ route('admin.empresas') }}">Empresas</a>
                        </li>
                        <li>
                            <a href="{{ route('admin.pagamentos') }}">Pagamentos</a>
                        </li>
                    </ul>
                </li>
                <li class="mt-2 sidebar-list">
                    <i class="fa fa-thumb-tack"></i>
                    <a class="sidebar-link sidebar-title link-nav" href="#">
                        <svg class="stroke-icon">
                            <use href="/assets/svg/icon-sprite.svg#stroke-landing-page"></use>
                        </svg>
                        <svg class="fill-icon">
                            <use href="/assets/svg/icon-sprite.svg#fill-landing-page"></use>
                        </svg><span>Ativar Plano</span>
                    </a>
                </li>
            @endif
            @if (auth()->user()->hasPermission('cliente'))
                <li class="mt-2 sidebar-list">
                    <i class="fa fa-thumb-tack"></i>
                    <a class="sidebar-link sidebar-title link-nav" href="{{ route('dashboard') }}">
                        <svg class="stroke-icon">
                            <use href="/assets/svg/icon-sprite.svg#stroke-home"></use>
                        </svg>
                        <svg class="fill-icon">
                            <use href="/assets/svg/icon-sprite.svg#fill-home"></use>
                        </svg><span>Dashboard</span>
                        <div class="according-menu"><i class="fa-angle-right fa"></i></div>
                    </a>
                </li>
                <li class="mt-2 sidebar-list">
                    <i class="fa fa-thumb-tack"></i>
                    <a class="sidebar-link sidebar-title link-nav" href="{{ route('agentes') }}">
                        <svg class="stroke-icon">
                            <use href="/assets/svg/icon-sprite.svg#stroke-ui-kits"></use>
                        </svg>
                        <svg class="fill-icon">
                            <use href="/assets/svg/icon-sprite.svg#fill-ui-kits"></use>
                        </svg><span>Agentes</span>
                    </a>
                </li>
                <li class="mt-2 sidebar-list">
                    <i class="fa fa-thumb-tack"></i>
                    <a class="sidebar-link sidebar-title link-nav" href="{{ route('agents.chats') }}">
                        <svg class="stroke-icon">
                            <use href="/assets/svg/icon-sprite.svg#stroke-chat"></use>
                        </svg>
                        <svg class="fill-icon">
                            <use href="/assets/svg/icon-sprite.svg#fill-chat"></use>
                        </svg><span>Chats</span>
                    </a>
                </li>
                @if (auth()->user()->hasPermission('creditos'))
                    <li class="mt-2 sidebar-list">
                        <i class="fa fa-thumb-tack"></i>
                        <a class="sidebar-link sidebar-title link-nav" href="{{ route('apis.config') }}">
                            <svg class="stroke-icon">
                                <use href="/assets/svg/icon-sprite.svg#stroke-learning"></use>
                            </svg>
                            <svg class="fill-icon">
                                <use href="/assets/svg/icon-sprite.svg#fill-learning"></use>
                            </svg><span>Gerenciar APIs</span>
                        </a>
                    </li>
                @endif
            @elseif(!auth()->user()->hasPermission('administrador'))
                <li class="mt-2 sidebar-list">
                    <i class="fa fa-thumb-tack"></i>
                    <a class="sidebar-link sidebar-title link-nav" href="#">
                        <svg class="stroke-icon">
                            <use href="/assets/svg/icon-sprite.svg#stroke-landing-page"></use>
                        </svg>
                        <svg class="fill-icon">
                            <use href="/assets/svg/icon-sprite.svg#fill-landing-page"></use>
                        </svg><span>Ativar Plano</span>
                    </a>
                </li>
            @endif
            @if (auth()->user()->hasPermission('franquia'))
                <li class="mt-2 sidebar-list">
                    <i class="fa fa-thumb-tack"></i>
                    <a class="sidebar-link sidebar-title link-nav" href="{{ route('empresa') }}">
                        <svg class="stroke-icon">
                            <use href="/assets/svg/icon-sprite.svg#stroke-blog"></use>
                        </svg>
                        <svg class="fill-icon">
                            <use href="/assets/svg/icon-sprite.svg#fill-blog"></use>
                        </svg><span>Gerenciar Empresa</span>
                    </a>
                </li>
            @endif
        </ul>
        <div class="right-arrow" id="right-arrow"><i data-feather="arrow-right"></i></div>
    </div>
</nav>
