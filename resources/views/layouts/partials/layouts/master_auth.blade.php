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

@yield('css')
@include('partials.head-css')

<body>
        
@include('partials.auth-background')
@include('partials.auth-header')
        @yield('content')

        @include('partials.vendor-scripts')

        @yield('js')

</body>

</html>
