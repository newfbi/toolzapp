@extends('partials.layouts.master')

@section('title', 'Index | Urbix Admin & Dashboards Template')
@section('title-sub', 'Dashboard')
@section('pagetitle', 'Media')
@section('css')
<link rel="stylesheet" type="text/css" href="assets/libs/jsvectormap/jsvectormap.min.css">
@endsection
@section('content')

                <div class="row">
                    <div class="col-xxl-9">
                        <div class="row">
                            <div class="col-xl-4 col-12">
                                <div class="card">
                                    <div class="card-body">
                                        <div class="d-flex align-items-center justify-content-between">
                                            <div class="d-flex align-items-center">
                                                <img src="assets/images/secialmedia/facebook.png" class="me-3 w-32px h-32px" alt="Facebook">
                                                <p class="mb-0 fw-semibold">Facebook</p>
                                            </div>
                                            <div class="dropdown">
                                                <a href="javascript:void(0)" data-bs-toggle="dropdown" class="text-body">
                                                    <i class="bi bi-three-dots"></i>
                                                </a>
                                                <ul class="dropdown-menu dropdown-menu-end">
                                                    <li><a class="dropdown-item" href="javascript:void(0)">This Week</a></li>
                                                    <li><a class="dropdown-item" href="javascript:void(0)">This Month</a></li>
                                                    <li><a class="dropdown-item" href="javascript:void(0)">This Year</a></li>
                                                </ul>
                                            </div>
                                        </div>
                                        <div class="mt-6 row g-0">
                                            <div class="col-xxl-6">
                                                <div class="pb-4 pb-xxl-0 border-bottom border-bottom-xxl-0 border-end-xxl">
                                                    <h2 class="mb-0">26,458</h2>
                                                    <p class="mb-0 fw-semibold">Follwers</p>
                                                </div>
                                            </div>
                                            <div class="col-xxl-6">
                                                <div class="mb-0 pt-4 pt-xxl-0 text-xxl-end">
                                                    <h4 class="mb-0">+9k</h4>
                                                    <p class="mb-0">likes/day</p>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-xl-4 col-12">
                                <div class="card">
                                    <div class="card-body">
                                        <div class="d-flex align-items-center justify-content-between">
                                            <div class="d-flex align-items-center">
                                                <img src="assets/images/secialmedia/instagram.png" class="me-3 w-32px h-32px" alt="Instagram">
                                                <p class="mb-0 fw-semibold">Instagram</p>
                                            </div>
                                            <div class="dropdown">
                                                <a href="javascript:void(0)" data-bs-toggle="dropdown" class="text-body">
                                                    <i class="bi bi-three-dots"></i>
                                                </a>
                                                <ul class="dropdown-menu dropdown-menu-end">
                                                    <li><a class="dropdown-item" href="javascript:void(0)">This Week</a></li>
                                                    <li><a class="dropdown-item" href="javascript:void(0)">This Month</a></li>
                                                    <li><a class="dropdown-item" href="javascript:void(0)">This Year</a></li>
                                                </ul>
                                            </div>
                                        </div>
                                        <div class="mt-6 row g-0">
                                            <div class="col-xxl-6">
                                                <div class="pb-4 pb-xxl-0 border-bottom border-bottom-xxl-0 border-end-xxl">
                                                    <h2 class="mb-0">33,854</h2>
                                                    <p class="mb-0 fw-semibold">Follwers</p>
                                                </div>
                                            </div>
                                            <div class="col-xxl-6">
                                                <div class="mb-0 pt-4 pt-xxl-0 text-xxl-end">
                                                    <h4 class="mb-0">+97k</h4>
                                                    <p class="mb-0">likes/day</p>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-xl-4">
                                <div class="card">
                                    <div class="card-body">
                                        <div class="d-flex align-items-center justify-content-between">
                                            <div class="d-flex align-items-center">
                                                <img src="assets/images/secialmedia/twitter.png" class="me-3 w-32px h-32px" alt="Twitter">
                                                <p class="mb-0 fw-semibold">Twitter</p>
                                            </div>
                                            <div class="dropdown">
                                                <a href="javascript:void(0)" data-bs-toggle="dropdown" class="text-body">
                                                    <i class="bi bi-three-dots"></i>
                                                </a>
                                                <ul class="dropdown-menu dropdown-menu-end">
                                                    <li><a class="dropdown-item" href="javascript:void(0)">This Week</a></li>
                                                    <li><a class="dropdown-item" href="javascript:void(0)">This Month</a></li>
                                                    <li><a class="dropdown-item" href="javascript:void(0)">This Year</a></li>
                                                </ul>
                                            </div>
                                        </div>
                                        <div class="mt-6 row g-0">
                                            <div class="col-xxl-6">
                                                <div class="pb-4 pb-xxl-0 border-bottom border-bottom-xxl-0 border-end-xxl">
                                                    <h2 class="mb-0">36,778</h2>
                                                    <p class="mb-0 fw-semibold">Follwers</p>
                                                </div>
                                            </div>
                                            <div class="col-xxl-6">
                                                <div class="mb-0 pt-4 pt-xxl-0 text-xxl-end">
                                                    <h4 class="mb-0">+149k</h4>
                                                    <p class="mb-0">likes/day</p>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-xxl-9">
                                <div class="card">
                                    <div class="card-header">
                                        <h4>Engagement Rate</h4>
                                    </div>
                                    <div class="card-body" id="engagement"></div>
                                </div>
                            </div>
                            <div class="col-xxl-3">
                                <div class="row">
                                    <div class="col-xxl-12 col-md-6">
                                        <div class="card">
                                            <div class="text-center card-body">
                                                <p class="mb-1 fs-18">Engaged Users</p>
                                                <h3 class="fw-semibold">12.2k</h3>
                                                <div id="spark1"></div>
                                                <p class="mb-0"><span class="text-success fw-medium"><i class="ri-arrow-up-fill"></i> </span>19%</p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-xxl-12 col-md-6">
                                        <div class="card">
                                            <div class="text-center card-body">
                                                <p class="mb-2 fs-18">Page Impression</p>
                                                <h3 class="fw-semibold">48</h3>
                                                <div id="spark2"></div>
                                                <p class="mb-0"><span class="text-danger fw-medium"><i class="ri-arrow-down-fill"></i> </span>8%</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-xxl-3">
                        <div class="row">
                            <div class="col-lg-12">
                                <div class="row">
                                    <div class="col-12">
                                        <div class="card">
                                            <div class="card-header">
                                                <h4>Visitor’s Profile</h4>
                                                <div class="dropdown">
                                                    <a href="javascript:void(0)" data-bs-toggle="dropdown" class="text-body">
                                                        <i class="bi bi-three-dots"></i>
                                                    </a>
                                                    <ul class="dropdown-menu dropdown-menu-end">
                                                        <li><a class="dropdown-item" href="javascript:void(0)">This Week</a></li>
                                                        <li><a class="dropdown-item" href="javascript:void(0)">This Month</a></li>
                                                        <li><a class="dropdown-item" href="javascript:void(0)">This Year</a></li>
                                                    </ul>
                                                </div>
                                            </div>
                                            <div class="card-body">
                                                <div id="donutChart"></div>
                                                <div class="row">
                                                    <div class="py-1 col-6">
                                                        <ul class="mb-0 list-unstyled">
                                                            <li class="d-flex justify-content-end">
                                                                <i class="me-2 text-primary bi bi-circle-fill"></i>
                                                                <h4 class="mb-0">Male</h4>
                                                            </li>
                                                        </ul>
                                                    </div>
                                                    <div class="py-1 col-6">
                                                        <ul class="mb-0 list-unstyled">
                                                            <li class="d-flex">
                                                                <i class="opacity-60 me-2 text-primary bi bi-circle-fill"></i>
                                                                <h4 class="mb-0">Female</h4>
                                                            </li>
                                                        </ul>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-12">
                                            <div class="card">
                                                <div class="mb-4 pb-5 card-header">
                                                    <h4>Page Reach</h4>
                                                    <div class="btn-group">
                                                        <button class="btn-outline-light text-body btn dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                                            Weekly
                                                        </button>
                                                        <div class="dropdown-menu dropdown-menu-end">
                                                            <a class="dropdown-item" href="javascript:void(0)">Weekly</a>
                                                            <a class="dropdown-item" href="javascript:void(0)">In Transit</a>
                                                            <a class="dropdown-item" href="javascript:void(0)">Delivered</a>
                                                            <a class="dropdown-item" href="javascript:void(0)">Pending</a>
                                                            <a class="dropdown-item" href="javascript:void(0)">Delayed</a>
                                                            <a class="dropdown-item" href="javascript:void(0)">Canceled</a>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="card-body">
                                                    <div class="d-xxl-flex align-items-center mb-1">
                                                        <h1>83.8</h1>
                                                        <p class="mb-0 fs-16 fw-normal">visitors per month</p>
                                                    </div>
                                                    <div class="d-flex align-items-center justify-content-center">
                                                        <div class="avatar-group justify-content-center">
                                                            <a href="#!" class="avatar-item">
                                                                <img class="img-fluid avatar-md" src="assets/images/avatar/avatar-1.jpg" alt="avatar image">
                                                            </a>
                                                            <a href="#!" class="avatar-item">
                                                                <img class="img-fluid avatar-md" src="assets/images/avatar/avatar-2.jpg" alt="avatar image">
                                                            </a>
                                                            <a href="#!" class="avatar-item">
                                                                <img class="img-fluid avatar-md" src="assets/images/avatar/avatar-3.jpg" alt="avatar image">
                                                            </a>
                                                        </div>
                                                        <p class="ms-2 mb-0">236+ New users</p>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-xxl-9">
                        <div class="card">
                            <div class="card-header">
                                <h4>Daily visitors</h4>
                                <div>
                                    <button class="btn btn-light">
                                        <i class="ri-arrow-left-wide-line"></i>
                                    </button>
                                    <button class="mx-3 btn btn-light">
                                        11-20 Jan
                                    </button>
                                    <button class="btn btn-light">
                                        <i class="ri-arrow-right-wide-fill"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-xxl-7">
                                        <div id="visitors"></div>
                                    </div>
                                    <div class="mt-auto col-xxl-5">
                                        <div class="row row-cols-3">
                                            <div class="col">
                                                <div class="bg-light p-6 rounded-3 text-center">
                                                    <h4>25.20K</h4>
                                                    <p class="mb-0">Facebook</p>
                                                </div>
                                            </div>
                                            <div class="col">
                                                <div class="bg-light p-6 rounded-3 text-center">
                                                    <h4>38.94K</h4>
                                                    <p class="mb-0">Instagram</p>
                                                </div>
                                            </div>
                                            <div class="col">
                                                <div class="bg-light p-6 rounded-3 text-center">
                                                    <h4>75.05K</h4>
                                                    <p class="mb-0">Twitter</p>
                                                </div>
                                            </div>
                                            <div class="mt-8 pt-3 col-12">
                                                <ul class="d-flex justify-content-around list-unstyled">
                                                    <li class="d-flex">
                                                        <div class="d-flex align-items-center">
                                                            <div class="d-flex align-items-center justify-content-center bg-primary-subtle me-3 rounded w-32px h-32px"></div>
                                                            <h6 class="mb-0 fs-16">Productivity</h6>
                                                        </div>
                                                    </li>
                                                    <li class="d-flex">
                                                        <div class="d-flex align-items-center">
                                                            <h6 class="mb-0 fs-16">+1.8K</h6>
                                                            <h6 class="mx-2 mb-0 fs-16">Productivity</h6>
                                                            <i class="text-success ri-arrow-up-long-line fs-5"></i>
                                                        </div>
                                                    </li>
                                                </ul>
                                                <ul class="d-flex justify-content-around list-unstyled">
                                                    <li class="d-flex">
                                                        <div class="d-flex align-items-center">
                                                            <div class="d-flex align-items-center justify-content-center bg-info me-3 rounded w-32px h-32px"></div>
                                                            <h6 class="mb-0 fs-16">Productivity</h6>
                                                        </div>
                                                    </li>
                                                    <li class="d-flex">
                                                        <div class="d-flex align-items-center">
                                                            <h6 class="mb-0 fs-16">-185</h6>
                                                            <h6 class="mx-2 mb-0 fs-16">Productivity</h6>
                                                            <i class="text-danger ri-arrow-down-long-line fs-5"></i>
                                                        </div>
                                                    </li>
                                                </ul>
                                                <ul class="d-flex justify-content-around list-unstyled">
                                                    <li class="d-flex">
                                                        <div class="d-flex align-items-center">
                                                            <div class="d-flex align-items-center justify-content-center bg-primary me-3 rounded w-32px h-32px"></div>
                                                            <h6 class="mb-0 fs-16">Productivity</h6>
                                                        </div>
                                                    </li>
                                                    <li class="d-flex">
                                                        <div class="d-flex align-items-center">
                                                            <h6 class="mb-0 fs-16">+230</h6>
                                                            <h6 class="mx-2 mb-0 fs-16">Productivity</h6>
                                                            <i class="text-success ri-arrow-up-long-line fs-5"></i>
                                                        </div>
                                                    </li>
                                                </ul>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-xxl-3">
                        <div class="card">
                            <div class="card-header">
                                <h4>Global Engagement</h4>
                                <div class="dropdown">
                                    <a href="javascript:void(0)" data-bs-toggle="dropdown" class="text-body" aria-expanded="false">
                                        <i class="bi bi-three-dots-vertical"></i>
                                    </a>
                                    <ul class="dropdown-menu dropdown-menu-end">
                                        <li><a class="dropdown-item" href="javascript:void(0)">This Week</a></li>
                                        <li><a class="dropdown-item" href="javascript:void(0)">This Month</a></li>
                                        <li><a class="dropdown-item" href="javascript:void(0)">This Year</a></li>
                                    </ul>
                                </div>
                            </div>
                            <div class="pt-0 card-body">
                                <div id="global"></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-xxl-4 col-lg-6">
                        <div class="card">
                            <div class="card-header">
                                <div>
                                    <h4>Post Activity</h4>
                                    <p class="mb-0 fs-14">From 15 Feb - 15 May,2024</p>
                                </div>
                                <a href="javascript:void(0)" class="btn btn-primary btn-sm">Change Period</a>
                            </div>
                            <div class="mt-6 border-bottom border-top row g-0 row-cols-3">
                                <div class="col">
                                    <div class="py-4 text-center">
                                        <h2>687</h2>
                                        <p class="mb-0 text-muted">Stories</p>
                                    </div>
                                </div>
                                <div class="col">
                                    <div class="py-4 text-center">
                                        <h2>189</h2>
                                        <p class="mb-0 text-muted">Posts</p>
                                    </div>
                                </div>
                                <div class="col">
                                    <div class="py-4 text-center">
                                        <h2>24</h2>
                                        <p class="mb-0 text-muted">Reels</p>
                                    </div>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="p-0">
                                    <div class="d-flex justify-content-around my-2 text-center">
                                        <h5>S</h5>
                                        <h5>M</h5>
                                        <h5>T</h5>
                                        <h5>W</h5>
                                        <h5>T</h5>
                                        <h5>F</h5>
                                        <h5>S</h5>
                                    </div>
                                    <div class="calendar-grid" id="calendar-grid"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-xxl-4 col-lg-6">
                        <div class="card">
                            <div class="card-header">
                                <div>
                                    <h4>Your Accounts</h4>
                                    <p class="mb-0 fs-14">You have 2 accounts</p>
                                </div>
                                <a href="javascript:void(0)" class="btn btn-primary btn-sm">+ Add Account</a>
                            </div>
                            <div class="card-body">
                                <div class="d-flex align-items-center gap-4 my-4">
                                    <div class="position-relative">
                                        <div class="avatar-item">
                                            <img class="img-fluid avatar-lg" src="assets/images/avatar/avatar-3.jpg" alt="avatar image">
                                        </div>
                                        <img src="assets/images/secialmedia/instagram.png" class="bottom-0 position-absolute bg-success border border-2 border-white rounded-circle w-20px h-20px end-0" alt="Instagram">
                                    </div>
                                    <div class="w-100">
                                        <div>
                                            <h6 class="mb-1 fs-16">@samanthawilliam_</h6>
                                            <div class="d-flex text-muted">
                                                <span>Instagram</span>
                                                <ul class="mb-0">
                                                    <li>278,534 Followers</li>
                                                </ul>
                                            </div>
                                        </div>
                                    </div>
                                    <a href="javascript:void(0)" class="btn-outline-primary btn">Active</a>
                                </div>
                                <hr>
                                <div class="d-flex align-items-center gap-4 my-4">
                                    <div class="position-relative">
                                        <div class="avatar-item">
                                            <img class="img-fluid avatar-lg" src="assets/images/avatar/avatar-3.jpg" alt="avatar image">
                                        </div>
                                        <img src="assets/images/secialmedia/twitter.png" class="bottom-0 position-absolute bg-success border border-2 border-white rounded-circle w-20px h-20px end-0" alt="Twitter">
                                    </div>
                                    <div class="w-100">
                                        <div>
                                            <h6 class="mb-1 fs-16">@samanthawilliam_</h6>
                                            <div class="d-flex text-muted">
                                                <span>Twitter / X</span>
                                                <ul class="mb-0">
                                                    <li>48,190 Followers</li>
                                                </ul>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="dropdown">
                                        <a href="javascript:void(0)" data-bs-toggle="dropdown" class="text-body" aria-expanded="false">
                                            <i class="bi bi-three-dots"></i>
                                        </a>
                                        <ul class="dropdown-menu dropdown-menu-end">
                                            <li><a class="dropdown-item" href="javascript:void(0)">This Week</a></li>
                                            <li><a class="dropdown-item" href="javascript:void(0)">This Month</a></li>
                                            <li><a class="dropdown-item" href="javascript:void(0)">This Year</a></li>
                                        </ul>
                                    </div>
                                </div>
                                <hr>
                                <div class="d-flex align-items-center gap-4 my-4">
                                    <div class="position-relative">
                                        <div class="avatar-item">
                                            <img class="img-fluid avatar-lg" src="assets/images/avatar/avatar-3.jpg" alt="avatar image">
                                        </div>
                                        <img src="assets/images/secialmedia/instagram.png" class="bottom-0 position-absolute bg-success border border-2 border-white rounded-circle w-20px h-20px end-0" alt="Instagram">
                                    </div>
                                    <div class="w-100">
                                        <div>
                                            <h6 class="mb-1 fs-16">@samanthawilliam_</h6>
                                            <div class="d-flex text-muted">
                                                <span>Dribble</span>
                                                <ul class="mb-0">
                                                    <li>48,190 Followers</li>
                                                </ul>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="dropdown">
                                        <a href="javascript:void(0)" data-bs-toggle="dropdown" class="text-body" aria-expanded="false">
                                            <i class="bi bi-three-dots"></i>
                                        </a>
                                        <ul class="dropdown-menu dropdown-menu-end">
                                            <li><a class="dropdown-item" href="javascript:void(0)">This Week</a></li>
                                            <li><a class="dropdown-item" href="javascript:void(0)">This Month</a></li>
                                            <li><a class="dropdown-item" href="javascript:void(0)">This Year</a></li>
                                        </ul>
                                    </div>
                                </div>
                                <hr>
                                <div class="d-flex align-items-center gap-4 my-4">
                                    <div class="position-relative">
                                        <div class="avatar-item">
                                            <img class="img-fluid avatar-lg" src="assets/images/avatar/avatar-3.jpg" alt="avatar image">
                                        </div>
                                        <img src="assets/images/secialmedia/behance.png" class="bottom-0 position-absolute bg-success border border-2 border-white rounded-circle w-20px h-20px end-0" alt="Behance">
                                    </div>
                                    <div class="w-100">
                                        <div>
                                            <h6 class="mb-1 fs-16">@samanthawilliam_</h6>
                                            <div class="d-flex text-muted">
                                                <span>Behance</span>
                                                <ul class="mb-0">
                                                    <li>48,190 Followers</li>
                                                </ul>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="dropdown">
                                        <a href="javascript:void(0)" data-bs-toggle="dropdown" class="text-body" aria-expanded="false">
                                            <i class="bi bi-three-dots"></i>
                                        </a>
                                        <ul class="dropdown-menu dropdown-menu-end">
                                            <li><a class="dropdown-item" href="javascript:void(0)">This Week</a></li>
                                            <li><a class="dropdown-item" href="javascript:void(0)">This Month</a></li>
                                            <li><a class="dropdown-item" href="javascript:void(0)">This Year</a></li>
                                        </ul>
                                    </div>
                                </div>
                                <hr>
                                <div class="d-flex align-items-center gap-4 my-4">
                                    <div class="position-relative">
                                        <div class="avatar-item">
                                            <img class="img-fluid avatar-lg" src="assets/images/avatar/avatar-3.jpg" alt="avatar image">
                                        </div>
                                        <img src="assets/images/secialmedia/youtube.png" class="bottom-0 position-absolute bg-success border border-2 border-white rounded-circle w-20px h-20px end-0" alt="You Tube">
                                    </div>
                                    <div class="w-100">
                                        <div>
                                            <h6 class="mb-1 fs-16">@samanthawilliam_</h6>
                                            <div class="d-flex text-muted">
                                                <span>YouTube</span>
                                                <ul class="mb-0">
                                                    <li>48,190 Followers</li>
                                                </ul>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="dropdown">
                                        <a href="javascript:void(0)" data-bs-toggle="dropdown" class="text-body" aria-expanded="false">
                                            <i class="bi bi-three-dots"></i>
                                        </a>
                                        <ul class="dropdown-menu dropdown-menu-end">
                                            <li><a class="dropdown-item" href="javascript:void(0)">This Week</a></li>
                                            <li><a class="dropdown-item" href="javascript:void(0)">This Month</a></li>
                                            <li><a class="dropdown-item" href="javascript:void(0)">This Year</a></li>
                                        </ul>
                                    </div>
                                </div>
                                <hr>
                            </div>
                        </div>
                    </div>
                    <div class="col-xxl-4">
                        <div class="card">
                            <div class="card-header">
                                <div>
                                    <h4>Post Insights</h4>
                                    <p class="mb-0 fs-14">Posted on May 10, 2024 -6:10pm</p>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="d-xl-flex align-items-center justify-content-between gap-4">
                                    <div>
                                        <h6 class="mb-1 fs-16">Accounts reached</h6>
                                        <h2 class="mb-0">5,192,879</h2>
                                    </div>
                                    <div id="reached"></div>
                                </div>
                                <hr>
                                <div class="d-xl-flex align-items-center justify-content-between gap-4">
                                    <div>
                                        <h6 class="mb-1 fs-16">Followers</h6>
                                        <h2 class="mb-0">+2,953</h2>
                                    </div>
                                    <div id="followers"></div>
                                </div>
                                <hr>
                                <div class="d-flex mt-4">
                                    <div>
                                        <h4>Unique Finding</h4>
                                        <p class="fw-semibold">You have gained more reach and followers when the post is related to workspace or productivity. Keep it up!</p>
                                        <a class="rounded-pill btn-outline-primary btn" href="javascript:void(0)">Subscribe for another finding</a>
                                    </div>
                                    <div class="mt-auto">
                                        <img src="assets/images/dashboard/laptop.png" class="img-fluid" width="300px" alt="Laptop">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-xxl-8">
                        <div class="card">
                            <div class="card-header">
                                <div>
                                    <h4 class="flex-grow-1">Global Reach</h4>
                                    <p class="mb-0 fs-14"><span class="text-success">+8.6%</span>Than last month</p>
                                </div>
                                <div class="d-flex align-items-center">
                                    <div class="position-relative d-flex align-items-center justify-content-center bg-light me-2 rounded-circle w-30px h-30px"><i class="ri-upload-cloud-line"></i></div>
                                    Export
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="d-flex">
                                    <div class="me-2 mt-auto">
                                        <div class="my-5 py-2 w-144px">
                                            <div class="w-100">
                                                <div class="d-flex align-items-center justify-content-between fs-13">
                                                    <p class="mb-1 text-muted">Indonesia</p>
                                                </div>
                                                <div class="bg-primary-subtle progress progress-sm">
                                                    <div class="bg-primary progress-bar" style="width: 75%"></div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="my-5 py-2 w-144px">
                                            <div class="w-100">
                                                <div class="d-flex align-items-center justify-content-between fs-13">
                                                    <p class="mb-1 text-muted">India</p>
                                                </div>
                                                <div class="bg-primary-subtle progress progress-sm">
                                                    <div class="bg-primary progress-bar" style="width: 60%"></div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="my-5 py-2 w-144px">
                                            <div class="w-100">
                                                <div class="d-flex align-items-center justify-content-between fs-13">
                                                    <p class="mb-1 text-muted">America</p>
                                                </div>
                                                <div class="bg-primary-subtle progress progress-sm">
                                                    <div class="bg-primary progress-bar" style="width: 90%"></div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="mt-5 pt-2 w-144px">
                                            <div class="w-100">
                                                <div class="d-flex align-items-center justify-content-between fs-13">
                                                    <p class="mb-1 text-muted">Europe</p>
                                                </div>
                                                <div class="bg-primary-subtle progress progress-sm">
                                                    <div class="bg-primary progress-bar" style="width: 25%"></div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="mt-auto">
                                        <div class="mt-5 pt-2 w-144px">
                                            <div class="w-100">
                                                <div class="d-flex align-items-center justify-content-between fs-13">
                                                    <p class="mb-1 text-muted">Other</p>
                                                </div>
                                                <div class="bg-primary-subtle progress progress-sm">
                                                    <div class="bg-primary progress-bar" style="width: 10%"></div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div id="world-map" class="min-h-320px"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-xxl-4">
                        <div class="card">
                            <div class="card-header">
                                <h4>Segmentation Age</h4>
                                <div class="dropdown">
                                    <a href="javascript:void(0)" data-bs-toggle="dropdown" class="text-body" aria-expanded="false">
                                        <i class="bi bi-three-dots"></i>
                                    </a>
                                    <ul class="dropdown-menu dropdown-menu-end">
                                        <li><a class="dropdown-item" href="javascript:void(0)">This Week</a></li>
                                        <li><a class="dropdown-item" href="javascript:void(0)">This Month</a></li>
                                        <li><a class="dropdown-item" href="javascript:void(0)">This Year</a></li>
                                    </ul>
                                </div>
                            </div>
                            <div class="card-body">
                                <ul class="d-flex mb-0 list-unstyled">
                                    <li class="d-flex me-5">
                                        <i class="me-2 text-primary bi bi-square-fill"></i>
                                        <h4>Male</h4>
                                    </li>
                                    <li class="d-flex">
                                        <i class="opacity-25 me-2 text-primary bi bi-square-fill"></i>
                                        <h4>Female</h4>
                                    </li>
                                </ul>
                                <div id="segmentation"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>

@endsection

@section('js')
    <!-- Vector map-->
    <script src="assets/libs/jsvectormap/jsvectormap.min.js"></script>
    <script src="assets/libs/jsvectormap/maps/world.js"></script>

    <script src="assets/libs/apexcharts/apexcharts.min.js"></script>

    <script src="assets/js/dashboard/media.init.js"></script>

    <!-- App js -->
    <script src="assets/js/app.js"></script>
@endsection