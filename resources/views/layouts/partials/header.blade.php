<!-- Begin Header -->
<header class="app-header" id="appHeader">
    <div class="w-100 container-fluid">
        <div class="d-flex align-items-center justify-content-between">
            <div class="d-inline-flex align-items-center gap-2">
                <a href="index" class="align-items-end me-5 logo-main d-none">
                    <img height="80" width="84" class="logo-dark" alt="Dark Logo" src="/assets/images/logo-md.png">
                    <h3 class="ms-1 mb-0 text-body-emphasis fw-bolder">TooLZapp</h3>
                </a>
                <button type="button" class="vertical-toggle btn header-btn" id="toggleSidebar"
                    aria-label="Toggle Sidebar">
                    <i class="bi-arrow-bar-left bi header-icon"></i>
                </button>
                <button type="button" class="horizontal-toggle btn header-btn d-none" id="toggleHorizontal"
                    aria-label="Toggle Menu">
                    <i class="ri-menu-2-line header-icon"></i>
                </button>
            </div>
            <div class="d-flex flex-shrink-0 align-items-center gap-4">
                <div class="d-flex align-items-center gap-2">
                    <div class="d-md-block pe-dropdown-mega dropdown d-none">
                        <button class="btn header-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false"
                            aria-label="Notifications">
                            <i class="bi bi-bell"></i>
                            <div class="icon-dot"></div>
                        </button>
                        <div class="p-0 pe-noti-dropdown-menu dropdown-menu dropdown-mega-md header-dropdown-menu">
                            <div class="p-3 border-bottom">
                                <h6 class="d-flex align-items-center mb-0">Notificações <span
                                        class="bg-success-subtle ms-auto text-success badge">0 Não Lidas</span></h6>
                            </div>
                            <div>
                                {{-- <div class="noti-item">
                                    <div
                                        class="d-flex align-items-center justify-content-center bg-success-subtle text-success avatar-md fs-16">
                                        <i class="bi bi-bag-check-fill"></i>
                                    </div>
                                    <div class="flex-grow-1">
                                        <a href="#!" class="text-decoration-none stretched-link">
                                            <h6 class="mb-1 fw-semibold">Item Back in Stock</h6>
                                        </a>
                                        <p class="mb-2 text-muted fs-12">Today, 02:45 PM</p>
                                        <div class="bg-body-tertiary bg-opacity-50 p-2 rounded">
                                            <p class="mb-0 lh-base fs-13">Good news! The item you wanted is back in
                                                stock. Grab it before it’s gone again!</p>
                                        </div>
                                    </div>
                                    <a href="#!"
                                        class="top-0 z-1 position-absolute me-3 mt-2 end-0 fs-18 link link-danger">
                                        <i class="bi bi-x"></i>
                                    </a>
                                </div> --}}
                            </div>
                        </div>
                    </div>
                </div>
                <div class="dark-mode-btn" id="toggleMode">
                    <button class="btn header-btn" id="lightModeBtn" type="button"
                        aria-label="Switch to light mode">
                        <i class="bi-brightness-high bi"></i>
                    </button>
                    <button class="btn header-btn active" id="darkModeBtn" type="button" aria-label="Switch to Dark mode">
                        <i class="bi bi-moon-stars"></i>
                    </button>
                </div>
                <div class="d-md-block pe-dropdown-mega dropdown d-none">
                    <button class="gap-1 text-start header-profile-btn btn" type="button" data-bs-toggle="dropdown"
                        aria-expanded="false">
                        <div class="d-xl-block pe-2 d-none">
                            <span class="d-block mb-0 fs-12 fw-semibold">{{ auth()->user()->name }}</span>
                            <span class="d-block mb-0 text-muted fs-10">{{ auth()->user()->email }}</span>
                        </div>
                        <span class="position-relative header-btn btn">
                            <img src="/assets/images/avatar/avatar-3.jpg" alt="Avatar Image"
                                class="rounded-circle img-fluid">
                        </span>
                    </button>
                    <div class="p-3 dropdown-menu dropdown-mega-sm header-dropdown-menu">
                        <div class="d-flex align-items-center gap-2 mb-2 pb-2 border-bottom">
                            <img src="/assets/images/avatar/avatar-3.jpg" alt="Avatar Image" class="avatar-md">
                            <div>
                                <a href="javascript:void(0)">
                                    <h6 class="mb-0 lh-base">{{ auth()->user()->name }}</h6>
                                </a>
                                <p class="mb-0 text-muted fs-13">{{ auth()->user()->email }}</p>
                            </div>
                        </div>
                        <ul class="mb-1 pb-1 border-bottom list-unstyled">
                            <li><a class="dropdown-item" href="{{ route('profile') }}"><i class="me-2 bi bi-person"></i>
                                    Perfil</a></li>
                            <li><a class="dropdown-item" href="javascript:void(0)"><i class="me-2 bi bi-gear"></i>
                                    Configuração</a></li>
                            <li><a class="dropdown-item" href="javascript:void(0)"><i class="me-2 bi bi-award"></i>
                                    Subscription</a></li>
                        </ul>
                        <ul class="mb-0 list-unstyled">
                            <li>
                                <form method="POST" action="{{ route('logout') }}">
                                    @csrf
                                    <a href="javascript:void(0)" class="dropdown-item"
                                        onclick="event.preventDefault(); this.closest('form').submit();">
                                        <i class="bi-box-arrow-right me-2 bi"></i>
                                        <span class="ml-2">Deslogar</span>
                                    </a>
                                </form>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
</header>
<!-- END Header -->
<!-- END Header -->
