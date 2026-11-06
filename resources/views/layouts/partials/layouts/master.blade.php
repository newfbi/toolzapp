<!DOCTYPE html>
<html lang="en">

<meta charset="utf-8" />
<title>@yield('title', ' | Urbix Admin & Dashboards Template')</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
<meta content="Admin & Dashboards Template" name="description" />
<meta content="Pixeleyez" name="author" />

<!-- layout setup -->
<script type="module" src="assets/js/layout-setup.js"></script>

<!-- App favicon -->
<link rel="shortcut icon" href="assets/images/favicon.png">
@livewireStyles
@stack('styles')
@yield('css')
@include('partials.head-css')

<body>
<div id="layout-wrapper">
    @include('partials.header')
    @include('partials.sidebar')
    @include('partials.horizontal')



    <main class="app-wrapper">
        <div class="container-fluid">

            @include('partials.page-title')
        @yield('content')

        @include('partials.switcher')
        @include('partials.scroll-to-top')
        @include('partials.footer')

        @include('partials.vendor-scripts')
        @livewireScripts
        @yield('js')

</body>

</html>
