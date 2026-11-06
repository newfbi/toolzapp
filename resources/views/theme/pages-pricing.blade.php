@extends('partials.layouts.master')

@section('title', 'Planos | Urbix Admin & Dashboards Template')
@section('title-sub', 'Página')
@section('pagetitle', 'Planos')

@section('content')
    <div class="mb-5 row gy-8">
        <div class="col-12">
            <div class="mb-10 text-center pricing-title">
                <h4>Pricing Plan 1</h4>
                <p class="text-muted">Plans that seamlessly adjust to your changing you always have the flexibility you need.
                </p>
            </div>
            <div class="row g-4">
                <div class="col-12 col-md-6 col-xl-3">
                    <div class="mb-0 overflow-hidden card">
                        <div class="card-body">
                            <div class="text-center">
                                <img src="assets/images/pages/starter.png" alt="Starter" class="w-80px img-fluid">
                                <div class="d-flex justify-content-center mt-2">
                                    <sup class="me-1 mt-3 mb-0 h4">$</sup>
                                    <h2 class="mb-0 fs-45 fw-normal">13</h2>
                                    <sup class="ms-1 mt-3 mb-0 h4">*</sup>
                                    <sub class="ms-1 mt-auto mb-4 text-muted h6 fw-normal">/mo</sub>
                                </div>
                                <p class="mt-2 mb-6 text-primary fw-medium fs-16">Save $19 a year</p>
                            </div>
                            <ul class="list-unstyled fs-15">
                                <li class="mb-5"><i class="me-2 text-primary bi bi-check-lg"></i><span>Solo or small
                                        teams.</span></li>
                                <li class="mb-5"><i class="me-2 text-primary bi bi-check-lg"></i><span>Basic tools
                                        included.</span></li>
                                <li class="mb-5"><i class="me-2 text-primary bi bi-check-lg"></i><span>1–2 users
                                        max.</span></li>
                                <li class="mb-5 text-muted"><i class="me-2 bi bi-x"></i><span>Limited storage.</span></li>
                                <li class="mb-5 text-muted"><i class="me-2 bi bi-x"></i><span>Email support only.</span>
                                </li>
                                <li class="mb-5 text-muted"><i class="me-2 bi bi-x"></i><span>Basic integrations.</span>
                                </li>
                                <li class="mb-5 text-muted"><i class="me-2 bi bi-x"></i><span>Free updates.</span></li>
                            </ul>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-md-6 col-xl-3">
                    <div class="right mb-0 overflow-hidden card ribbon">
                        <span class="ribbon2 ribbon-primary">
                            <span>Popular</span>
                        </span>
                        <div class="card-body">
                            <div class="text-center">
                                <img src="assets/images/pages/enterprise.png" alt="Enterprise" class="w-80px img-fluid">
                                <div class="d-flex justify-content-center mt-2">
                                    <sup class="me-1 mt-3 mb-0 h4">$</sup>
                                    <h2 class="mb-0 fs-45 fw-normal">25</h2>
                                    <sup class="ms-1 mt-3 mb-0 h4">*</sup>
                                    <sub class="ms-1 mt-auto mb-4 text-muted h6 fw-normal">/mo</sub>
                                </div>
                                <p class="mt-2 mb-6 text-primary fw-medium fs-16">Best for growing teams</p>
                            </div>
                            <ul class="list-unstyled fs-15">
                                <li class="mb-5"><i class="me-2 text-primary bi bi-check-lg"></i><span>Up to 5 users
                                        included.</span></li>
                                <li class="mb-5"><i class="me-2 text-primary bi bi-check-lg"></i><span>Core tools
                                        enabled.</span></li>
                                <li class="mb-5"><i class="me-2 text-primary bi bi-check-lg"></i><span>Basic analytics
                                        access.</span></li>
                                <li class="mb-5 text-muted"><i class="me-2 bi bi-x"></i><span>Limited custom options.</span>
                                </li>
                                <li class="mb-5 text-muted"><i class="me-2 bi bi-x"></i><span>Email & chat only.</span></li>
                                <li class="mb-5 text-muted"><i class="me-2 bi bi-x"></i><span>Standard integrations.</span>
                                </li>
                                <li class="mb-5 text-muted"><i class="me-2 bi bi-x"></i><span>Quarterly updates.</span></li>
                            </ul>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-md-6 col-xl-3">
                    <div class="mb-0 overflow-hidden card">
                        <div class="card-body">
                            <div class="text-center">
                                <img src="assets/images/pages/growth.png" alt="Growth" class="w-80px img-fluid">
                                <div class="d-flex justify-content-center mt-2">
                                    <sup class="me-1 mt-3 mb-0 h4">$</sup>
                                    <h2 class="mb-0 fs-45 fw-normal">45</h2>
                                    <sup class="ms-1 mt-3 mb-0 h4">*</sup>
                                    <sub class="ms-1 mt-auto mb-4 text-muted h6 fw-normal">/mo</sub>
                                </div>
                                <p class="mt-2 mb-6 text-primary fw-medium fs-16">Great for scaling teams</p>
                            </div>
                            <ul class="list-unstyled fs-15">
                                <li class="mb-5"><i class="me-2 text-primary bi bi-check-lg"></i><span>Supports 10+
                                        users.</span></li>
                                <li class="mb-5"><i class="me-2 text-primary bi bi-check-lg"></i><span>Advanced reporting
                                        tools.</span></li>
                                <li class="mb-5"><i class="me-2 text-primary bi bi-check-lg"></i><span>Priority email
                                        support.</span></li>
                                <li class="mb-5"><i class="me-2 text-primary bi bi-check-lg"></i><span>Enhanced security
                                        options.</span></li>
                                <li class="mb-5 text-muted"><i class="me-2 bi bi-x"></i><span>Manager not included.</span>
                                </li>
                                <li class="mb-5 text-muted"><i class="me-2 bi bi-x"></i><span>Custom training
                                        optional.</span></li>
                                <li class="mb-5 text-muted"><i class="me-2 bi bi-x"></i><span>Annual updates.</span></li>
                            </ul>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-md-6 col-xl-3">
                    <div class="mb-0 overflow-hidden card">
                        <div class="card-body">
                            <div class="text-center">
                                <img src="assets/images/pages/basic.png" alt="Basic" class="w-80px img-fluid">
                                <div class="d-flex justify-content-center mt-2">
                                    <sup class="me-1 mt-3 mb-0 h4">$</sup>
                                    <h2 class="mb-0 fs-45 fw-normal">99</h2>
                                    <sup class="ms-1 mt-3 mb-0 h4">*</sup>
                                    <sub class="ms-1 mt-auto mb-4 text-muted h6 fw-normal">/mo</sub>
                                </div>
                                <p class="mt-2 mb-6 text-primary fw-medium fs-16">Full access with support</p>
                            </div>
                            <ul class="list-unstyled fs-15">
                                <li class="mb-5"><i class="me-2 text-primary bi bi-check-lg"></i><span>Unlimited users &
                                        teams.</span></li>
                                <li class="mb-5"><i class="me-2 text-primary bi bi-check-lg"></i><span>Custom advanced
                                        features.</span></li>
                                <li class="mb-5"><i class="me-2 text-primary bi bi-check-lg"></i><span>24/7 dedicated
                                        support.</span></li>
                                <li class="mb-5"><i class="me-2 text-primary bi bi-check-lg"></i><span>Full
                                        integrations.</span></li>
                                <li class="mb-5"><i class="me-2 text-primary bi bi-check-lg"></i><span>Custom
                                        onboarding.</span></li>
                                <li class="mb-5"><i class="me-2 text-primary bi bi-check-lg"></i><span>Daily backups &
                                        reports.</span></li>
                                <li class="mb-5"><i class="me-2 text-primary bi bi-check-lg"></i><span>Roadmap priority
                                        access.</span></li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-12">
            <div class="mb-10 text-center">
                <h4>Pricing Plan 2</h4>
                <p class="text-muted">Plans that seamlessly adjust to your changing you always have the flexibility you
                    need.</p>
            </div>
            <ul class="bg-primary-subtle mx-auto p-2 rounded w-max nav nav-pills" role="tablist">
                <li class="nav-item" role="presentation">
                    <a class="nav-link active" data-bs-toggle="tab" href="#monthly-pricing" role="tab"
                        aria-selected="false" tabindex="-1">
                        <span><i class="fas fa-home"></i></span>
                        <span>Monthly</span>
                    </a>
                </li>
                <li class="nav-item" role="presentation">
                    <a class="nav-link" data-bs-toggle="tab" href="#yearly-pricing" role="tab"
                        aria-selected="true">
                        <span><i class="far fa-user"></i></span>
                        <span>Yearly</span>
                    </a>
                </li>
            </ul>
            <div class="pt-8 tab-content">
                <div class="tab-pane active show" id="monthly-pricing" role="tabpanel">
                    <div class="align-items-end justify-content-center row g-4">
                        <div class="col-12 col-xl-4 col-xxl-3">
                            <div class="mb-0 overflow-hidden card">
                                <div class="card-body">
                                    <div class="text-center">
                                        <h5 class="text-primary text-uppercase fw-medium">Starter</h5>
                                        <div class="d-flex justify-content-center mt-5">
                                            <sup class="me-1 mt-3 mb-0 h4">$</sup>
                                            <h2 class="mb-0 fs-45 fw-normal">13</h2>
                                            <sup class="ms-1 mt-3 mb-0 h4">*</sup>
                                            <sub class="ms-1 mt-auto mb-4 text-muted h6 fw-normal">/mo</sub>
                                        </div>
                                        <p class="mt-2 mb-6 text-primary fw-medium fs-16">Save $19 a year</p>
                                    </div>
                                    <ul class="text-body text-center list-unstyled fs-15">
                                        <li class="mb-4"><i class="me-2 text-primary bi bi-check-lg"></i><span>Made for
                                                small teams and solo users.</span></li>
                                        <li class="mb-4"><i class="me-2 text-primary bi bi-check-lg"></i><span>Includes
                                                key tools with usage limits.</span></li>
                                        <li class="mb-4"><i class="me-2 text-primary bi bi-check-lg"></i><span>Supports
                                                1–2 users per account.</span></li>
                                        <li class="mb-4 text-muted"><i class="me-2 bi bi-x"></i><span>Storage and
                                                bandwidth
                                                are limited.</span></li>
                                        <li class="mb-4 text-muted"><i class="me-2 bi bi-x"></i><span>Email help during
                                                business hours.</span></li>
                                        <li class="mb-4 text-muted"><i class="me-2 bi bi-x"></i><span>Works with main
                                                tools
                                                and services.</span></li>
                                        <li class="mb-4 text-muted"><i class="me-2 bi bi-x"></i><span>Core features
                                                updated
                                                for free.</span></li>
                                    </ul>
                                </div>
                            </div>
                        </div><!--End col-->
                        <div class="col-12 col-xl-4 col-xxl-3">
                            <div class="right mb-0 overflow-hidden card ribbon">
                                <span class="ribbon2 ribbon-primary">
                                    <span>Popular</span>
                                </span>
                                <div class="card-body">
                                    <div class="text-center">
                                        <h5 class="text-primary text-uppercase fw-medium">Basic</h5>
                                        <div class="d-flex justify-content-center mt-5">
                                            <sup class="me-1 mt-3 mb-0 h4">$</sup>
                                            <h2 class="mb-0 fs-45 fw-normal">25</h2>
                                            <sup class="ms-1 mt-3 mb-0 h4">*</sup>
                                            <sub class="ms-1 mt-auto mb-4 text-muted h6 fw-normal">/mo</sub>
                                        </div>
                                        <p class="mt-2 mb-6 text-primary fw-medium fs-16">Best for growing teams</p>
                                    </div>
                                    <ul class="text-body text-center list-unstyled fs-15">
                                        <li class="mb-4"><i class="me-2 text-primary bi bi-check-lg"></i><span>Supports
                                                up to 5 users.</span></li>
                                        <li class="mb-4"><i class="me-2 text-primary bi bi-check-lg"></i><span>Essential
                                                tools unlocked.</span></li>
                                        <li class="mb-4"><i class="me-2 text-primary bi bi-check-lg"></i><span>Basic
                                                analytics and insights.</span></li>
                                        <li class="mb-4 text-muted"><i class="me-2 bi bi-x"></i><span>Limited
                                                customization.</span></li>
                                        <li class="mb-4 text-muted"><i class="me-2 bi bi-x"></i><span>Email + chat
                                                support.</span></li>
                                        <li class="mb-4 text-muted"><i class="me-2 bi bi-x"></i><span>Only standard
                                                integrations.</span></li>
                                        <li class="mb-4 text-muted"><i class="me-2 bi bi-x"></i><span>Quarterly feature
                                                updates.</span></li>
                                    </ul>
                                </div>
                            </div>
                        </div><!--End col-->
                        <div class="col-12 col-xl-4 col-xxl-3">
                            <div class="mb-0 overflow-hidden card">
                                <div class="card-body">
                                    <div class="text-center">
                                        <h5 class="text-primary text-uppercase fw-medium">Premium</h5>
                                        <div class="d-flex justify-content-center mt-5">
                                            <sup class="me-1 mt-3 mb-0 h4">$</sup>
                                            <h2 class="mb-0 fs-45 fw-normal">45</h2>
                                            <sup class="ms-1 mt-3 mb-0 h4">*</sup>
                                            <sub class="ms-1 mt-auto mb-4 text-muted h6 fw-normal">/mo</sub>
                                        </div>
                                        <p class="mt-2 mb-6 text-primary fw-medium fs-16">Great for scaling teams</p>
                                    </div>
                                    <ul class="mx-auto text-center list-unstyled fs-15">
                                        <li class="mb-4"><i class="me-2 text-primary bi bi-check-lg"></i><span>Supports
                                                10+ users.</span></li>
                                        <li class="mb-4"><i class="me-2 text-primary bi bi-check-lg"></i><span>Advanced
                                                reporting tools.</span></li>
                                        <li class="mb-4"><i class="me-2 text-primary bi bi-check-lg"></i><span>Priority
                                                email support.</span></li>
                                        <li class="mb-4"><i class="me-2 text-primary bi bi-check-lg"></i><span>Enhanced
                                                security options.</span></li>
                                        <li class="mb-4 text-muted"><i class="me-2 bi bi-x"></i><span>Dedicated manager
                                                not included.</span></li>
                                        <li class="mb-4 text-muted"><i class="me-2 bi bi-x"></i><span>Custom training
                                                optional.</span></li>
                                        <li class="mb-4 text-muted"><i class="me-2 bi bi-x"></i><span>Annual
                                                updates.</span></li>
                                    </ul>
                                </div>
                            </div>
                        </div><!--End col-->
                    </div>
                </div>
                <div class="tab-pane" id="yearly-pricing" role="tabpanel">
                    <div class="align-items-end justify-content-center row g-4">
                        <div class="col-12 col-xl-4 col-xxl-3">
                            <div class="mb-0 overflow-hidden card">
                                <div class="card-body">
                                    <div class="text-center">
                                        <h5 class="text-primary text-uppercase fw-medium">Starter</h5>
                                        <div class="d-flex justify-content-center mt-5">
                                            <sup class="me-1 mt-3 mb-0 h4">$</sup>
                                            <h2 class="mb-0 fs-45 fw-normal">129</h2>
                                            <sup class="ms-1 mt-3 mb-0 h4">*</sup>
                                            <sub class="ms-1 mt-auto mb-4 text-muted h6 fw-normal">/yr</sub>
                                        </div>
                                        <p class="mt-2 mb-6 text-primary fw-medium fs-16">Save $27 a year</p>
                                    </div>
                                    <ul class="text-body text-center list-unstyled fs-15">
                                        <li class="mb-4"><i class="me-2 text-primary bi bi-check-lg"></i><span>Made for
                                                small teams and solo users.</span></li>
                                        <li class="mb-4"><i class="me-2 text-primary bi bi-check-lg"></i><span>Includes
                                                key tools with usage limits.</span></li>
                                        <li class="mb-4"><i class="me-2 text-primary bi bi-check-lg"></i><span>Supports
                                                1–2 users per account.</span></li>
                                        <li class="mb-4 text-muted"><i class="me-2 bi bi-x"></i><span>Storage and
                                                bandwidth are limited.</span></li>
                                        <li class="mb-4 text-muted"><i class="me-2 bi bi-x"></i><span>Email help during
                                                business hours.</span></li>
                                        <li class="mb-4 text-muted"><i class="me-2 bi bi-x"></i><span>Works with main
                                                tools and services.</span></li>
                                        <li class="mb-4 text-muted"><i class="me-2 bi bi-x"></i><span>Core features
                                                updated for free.</span></li>
                                    </ul>
                                </div>
                            </div>
                        </div><!--End col-->
                        <div class="col-12 col-xl-4 col-xxl-3">
                            <div class="right mb-0 overflow-hidden card ribbon">
                                <span class="ribbon2 ribbon-primary">
                                    <span>Popular</span>
                                </span>
                                <div class="card-body">
                                    <div class="text-center">
                                        <h5 class="text-primary text-uppercase fw-medium">Basic</h5>
                                        <div class="d-flex justify-content-center mt-5">
                                            <sup class="me-1 mt-3 mb-0 h4">$</sup>
                                            <h2 class="mb-0 fs-45 fw-normal">240</h2>
                                            <sup class="ms-1 mt-3 mb-0 h4">*</sup>
                                            <sub class="ms-1 mt-auto mb-4 text-muted h6 fw-normal">/yr</sub>
                                        </div>
                                        <p class="mt-2 mb-6 text-primary fw-medium fs-16">Best for growing teams</p>
                                    </div>
                                    <ul class="text-body text-center list-unstyled fs-15">
                                        <li class="mb-4"><i class="me-2 text-primary bi bi-check-lg"></i><span>Supports
                                                up to 5 users.</span></li>
                                        <li class="mb-4"><i class="me-2 text-primary bi bi-check-lg"></i><span>Essential
                                                tools unlocked.</span></li>
                                        <li class="mb-4"><i class="me-2 text-primary bi bi-check-lg"></i><span>Basic
                                                analytics and insights.</span></li>
                                        <li class="mb-4 text-muted"><i class="me-2 bi bi-x"></i><span>Limited
                                                customization.</span></li>
                                        <li class="mb-4 text-muted"><i class="me-2 bi bi-x"></i><span>Email + chat
                                                support.</span></li>
                                        <li class="mb-4 text-muted"><i class="me-2 bi bi-x"></i><span>Only standard
                                                integrations.</span></li>
                                        <li class="mb-4 text-muted"><i class="me-2 bi bi-x"></i><span>Quarterly feature
                                                updates.</span></li>
                                    </ul>
                                </div>
                            </div>
                        </div><!--End col-->
                        <div class="col-12 col-xl-4 col-xxl-3">
                            <div class="mb-0 overflow-hidden card">
                                <div class="card-body">
                                    <div class="text-center">
                                        <h5 class="text-primary text-uppercase fw-medium">Premium</h5>
                                        <div class="d-flex justify-content-center mt-5">
                                            <sup class="me-1 mt-3 mb-0 h4">$</sup>
                                            <h2 class="mb-0 fs-45 fw-normal">430</h2>
                                            <sup class="ms-1 mt-3 mb-0 h4">*</sup>
                                            <sub class="ms-1 mt-auto mb-4 text-muted h6 fw-normal">/yr</sub>
                                        </div>
                                        <p class="mt-2 mb-6 text-primary fw-medium fs-16">Great for scaling teams</p>
                                    </div>
                                    <ul class="mx-auto text-center list-unstyled fs-15">
                                        <li class="mb-4"><i class="me-2 text-primary bi bi-check-lg"></i><span>Supports
                                                10+ users.</span></li>
                                        <li class="mb-4"><i class="me-2 text-primary bi bi-check-lg"></i><span>Advanced
                                                reporting tools.</span></li>
                                        <li class="mb-4"><i class="me-2 text-primary bi bi-check-lg"></i><span>Priority
                                                email support.</span></li>
                                        <li class="mb-4"><i class="me-2 text-primary bi bi-check-lg"></i><span>Enhanced
                                                security options.</span></li>
                                        <li class="mb-4 text-muted"><i class="me-2 bi bi-x"></i><span>Dedicated manager
                                                not included.</span></li>
                                        <li class="mb-4 text-muted"><i class="me-2 bi bi-x"></i><span>Custom training
                                                optional.</span></li>
                                        <li class="mb-4 text-muted"><i class="me-2 bi bi-x"></i><span>Annual
                                                updates.</span></li>
                                    </ul>
                                </div>
                            </div>
                        </div><!--End col-->
                    </div>
                </div>
            </div>
        </div><!--End col-->
    </div>
    </div>
    </main>
    </div>

@endsection

@section('js')

    <!-- App js -->
    <script src="assets/js/app.js"></script>

@endsection
