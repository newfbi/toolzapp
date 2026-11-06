<!DOCTYPE html>
<html lang="en">

<!-- Title Meta -->
@include('layouts.partials.title-meta')

<!-- layout setup -->
<script type="module" src="/assets/js/layout-setup.js"></script>

<!-- App favicon -->
<link rel="shortcut icon" href="/assets/images/favicon.png">
@include('layouts.partials.head-css')

<body>
    <div id="layout-wrapper">
        @include('layouts.partials.header')
        @include('layouts.partials.sidebar')
        @include('layouts.partials.horizontal')
        <main class="app-wrapper">
            {{ $slot }}
        </main>
    </div>
    @include('layouts.partials.switcher')
    @include('layouts.partials.scroll-to-top')
    @include('layouts.partials.footer')
    @include('layouts.partials.vendor-scripts')
</body>

</html>
