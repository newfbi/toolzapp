<div class="main-breadcrumb d-flex align-items-center my-3 position-relative">
    <h2 class="breadcrumb-title mb-0 flex-grow-1 fs-14">@yield('pagetitle')</h2>
    <div class="flex-shrink-0">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb justify-content-end mb-0">
                <li class="breadcrumb-item"><a href="javascript:void(0)">@yield('title-sub')</a></li>
                <li class="breadcrumb-item active" aria-current="page">@yield('pagetitle')</li>
            </ol>
        </nav>
    </div>
</div>