<div class="page-header">
    <div class="m-0 header-wrapper row">
        <form class="form-inline search-full col" action="#" method="get">
            <div class="form-group w-100">
                <div class="Typeahead Typeahead--twitterUsers">
                    <div class="u-posRelative">
                        <input class="w-100 demo-input Typeahead-input form-control-plaintext" type="text"
                            placeholder="Search Riho .." name="q" title="" autofocus>
                        <div class="spinner-border Typeahead-spinner" role="status"><span class="sr-only">Aguarde...
                            </span></div><i class="close-search" data-feather="x"></i>
                    </div>
                    <div class="Typeahead-menu"> </div>
                </div>
            </div>
        </form>
        <div class="p-0 header-logo-wrapper col-auto">
            <div class="logo-wrapper"> <a href="index.html"><img class="img-fluid for-light"
                        src="/assets/images/logo/logo_dark.png" alt="logo-light"><img class="img-fluid for-dark"
                        src="/assets/images/logo/logo.png" alt="logo-dark"></a></div>
            <div class="toggle-sidebar"> <i class="status_toggle middle sidebar-toggle" data-feather="align-center"></i>
            </div>
        </div>
        <div class="left-header p-0 col-xxl-5 col-xl-6 col-lg-5 col-md-4 col-sm-3">
            <div> <a class="toggle-sidebar" href="#"> <i class="iconly-Category icli"> </i></a>
                <div class="d-flex align-items-center gap-2">
                    <h4 class="f-w-600">{{ $title ?? 'Dashboard' }}</h4><img class="mt-0"
                        src="/assets/images/hand.gif" alt="hand-gif">
                </div>
            </div>
            <div class="d-xl-block welcome-content d-none"><span class="text-truncate col-12">Gerenciamento dos agentes
                    de atendimento. </span></div>
        </div>
        <div class="nav-right pull-right right-header ms-auto p-0 col-xxl-7 col-xl-6 col-md-7 col-8">
            <ul class="nav-menus">
                <li>
                    <div class="mode"><i class="moon" data-feather="moon"> </i></div>
                </li>
                <li class="onhover-dropdown notification-down">
                    <div class="notification-box">
                        <svg>
                            <use href="/assets/svg/icon-sprite.svg#notification-header"></use>
                        </svg><span class="rounded-pill badge badge-secondary">0 </span>
                    </div>
                    <div class="onhover-show-div notification-dropdown">
                        <div class="mb-0 card">
                            <div class="card-header">
                                <div class="common-space">
                                    <h4 class="f-w-600 text-start">notificações</h4>
                                    <div><span>0 Novas</span></div>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="notitications-bar">
                                    <div class="tab-content" id="pills-tabContent">
                                        <div class="tab-pane fade show active" id="pills-blog" role="tabpanel"
                                            aria-labelledby="pills-blog-tab">
                                            <div class="notification-card">
                                                <ul>
                                                    {{--  <li
                                                        class="d-flex align-items-center justify-content-between w-100 notification">
                                                        <div
                                                            class="d-flex align-items-center justify-content-center gap-2 w-100 notification-data">
                                                            <div class="flex-shrink-0 user-alerts"><img
                                                                    class="rounded-circle img-fluid img-40"
                                                                    src="/assets/images/dashboard/user/3.jpg"
                                                                    alt="user" /></div>
                                                            <div class="flex-grow-1">
                                                                <div class="w-100 common-space user-id">
                                                                    <div class="w-100 common-space"> <a
                                                                            class="f-w-500 f-12"
                                                                            href="/template/private-chat.html">Robert D.
                                                                            Hambly</a></div>
                                                                </div>
                                                                <div><span class="f-w-500 f-light f-12">Hello
                                                                        Miss...😊</span></div>
                                                            </div><span class="f-w-500 f-light f-12">44 sec</span>
                                                        </div>
                                                    </li> --}}
                                                </ul>
                                            </div>
                                        </div>
                                        <div class="pr-0 pb-0 pl-0 card-footer">
                                            <div class="text-center"> <a class="f-w-700" href="#">
                                                    <button class="btn btn-primary" type="button"
                                                        title="btn btn-primary">Ler todas</button></a>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </li>
                <li class="profile-nav onhover-dropdown">
                    <div class="media profile-media">
                        <img class="b-r-10" width="50px" src="/assets/images/logo/logo-rounded.png" alt="">
                        <div class="d-xxl-block media-body d-none box-col-none">
                            <div class="d-flex align-items-center gap-2"> <span>{{ auth()->user()->name }} </span><i
                                    class="middle fa fa-angle-down"> </i></div>
                            @if (auth()->user()->hasPermission('administador'))
                                <p class="mb-0 font-roboto">Admin</p>
                            @else
                                <p class="mb-0 font-roboto">Vencimento:
                                    {{ date(strtotime(auth()->user()->vencimento)) }}</p>
                            @endif
                        </div>
                    </div>
                    <ul class="profile-dropdown onhover-show-div">
                        <li><a href="{{ route('profile') }}"><i data-feather="user"></i><span>Meu Perfil</span></a></li>
                        <li><a href=#"><i data-feather="mail"></i><span>Mensagens</span></a></li>
                        <li> <a href="#"> <i data-feather="settings"></i><span>Configuração</span></a>
                        </li>

                        <li>
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <a href="javascript:void(0)" class="btn-outline-primary btn btn-pill btn-sm"
                                    onclick="event.preventDefault(); this.closest('form').submit();">
                                    <span class="ml-2">Log Out</span>
                                </a>
                            </form>
                        </li>
                    </ul>
                </li>
            </ul>
        </div>
        <script class="result-template" type="text/x-handlebars-template">
            <div class="ProfileCard u-cf">
            <div class="ProfileCard-avatar"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="m-0 feather feather-airplay"><path d="M5 17H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2h-1"></path><polygon points="12 15 17 21 7 21 12 15"></polygon></svg></div>
            <div class="ProfileCard-details">
            <div class="ProfileCard-realName">Ai ChatBot Agentes</div>
            </div>
            </div>
          </script>
        <script class="empty-template" type="text/x-handlebars-template"><div class="EmptyMessage">Your search turned up 0 results. This most likely means the backend is down, yikes!</div></script>
    </div>
</div>
