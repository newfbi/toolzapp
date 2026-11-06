@extends('partials.layouts.master2')

@section('title', 'Coming Soon | Urbix Admin & Dashboards Template')

@section('content')

<!-- START -->
<div>
  <img src="assets/images/vector/planet.png" alt="Startup Plan" class="startup-img object-fit-cover d-none d-xl-block">
  <img src="assets/images/vector/launch.png" alt="Startup Plan" class="launch-img object-fit-cover animate-bounce d-none d-xl-block">
  <div class="floating-shape animate-circle shape-square square-1 d-none d-lg-block"></div>
  <div class="floating-shape animate-circle shape-square square-2 d-none d-lg-block"></div>
  <div class="floating-shape animate-circle shape-triangle triangle-1 d-none d-lg-block"></div>
  <div class="floating-shape animate-circle shape-triangle triangle-2 d-none d-lg-block"></div>
  <div class="px-3 px-md-8 py-5 position-absolute top-0 d-flex justify-content-between align-items-center w-100 z-1">
    <a href="index" class="d-flex align-items-end logo-main">
      <img height="35" class="logo-dark" alt="Dark Logo" src="assets/images/logo-md.png">
      <h3 class="text-body-emphasis fw-bolder mb-0 ms-1">Urbix</h3>
    </a>
    <a href="index" class="link-primary"><i class="ri-arrow-left-line me-2"></i>Back</a>
  </div>
  <div class="container">
    <div class="row position-relative justify-content-center align-items-center min-vh-100">
      <div class="col-lg-8">
        <div class="text-center py-20 py-md-16">
          <h1 class="mb-4 fs-55 text-primary">We’re Launching Soon!</h1>
          <p class="max-w-600px mx-auto fs-15 text-muted">Something exciting is on the way. Stay tuned — we’re almost ready to launch. Subscribe below to be the first to know when we go live.</p>
          <form class="mt-12">
            <div id="countdown">
              <ul class="list-inline text-center d-flex justify-content-center flex-wrap flex-md-row gap-1">
                <li class="list-inline-item p-4 min-w-144px rounded-2">
                  <h2 class="mb-1" id="days">00</h2>
                  <span>days</span>
                </li>
                <li class="list-inline-item p-4 min-w-144px rounded-2">
                  <h2 class="mb-1" id="hours">00</h2>
                  <span>hours</span>
                </li>
                <li class="list-inline-item p-4 min-w-144px rounded-2">
                  <h2 class="mb-1" id="minutes">00</h2>
                  <span>minutes</span>
                </li>
                <li class="list-inline-item p-4 min-w-144px rounded-2">
                  <h2 class="mb-1" id="seconds">00</h2>
                  <span>seconds</span>
                </li>
              </ul>
            </div>
            <div class="position-relative subscribe-btn max-w-500px mt-10 mx-auto">
              <input type="text" class="form-control min-h-56px form-control-icon" id="companyNameLayout4" placeholder="Enter Your Email" required>
              <button class="btn btn-primary">Subscribe</button>
            </div>
          </form>
          <div class="mt-6 text-muted">No spam — only product updates and launch info.</div>
          <div class="mt-8 d-flex justify-content-center gap-2 fs-20">
            <a href="#!" class="btn btn-primary icon-btn rounded-pill"><i class="bi bi-facebook"></i></a>
            <a href="#!" class="btn btn-primary icon-btn rounded-pill"><i class="bi bi-twitter-x"></i></a>
            <a href="#!" class="btn btn-primary icon-btn rounded-pill"><i class="bi bi-instagram"></i></a>
            <a href="#!" class="btn btn-primary icon-btn rounded-pill"><i class="bi bi-linkedin"></i></a>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

@endsection

@section('js')
<script src="assets/js/auth/coming-soon.init.js"></script>
<script src="assets/js/app.js"></script>
@endsection