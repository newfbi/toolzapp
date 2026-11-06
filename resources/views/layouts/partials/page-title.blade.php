@php
    $title = $title ?? '';
    $breadcrumbItems = $breadcrumbItems ?? [];
@endphp

<div class="position-relative d-flex align-items-center my-3 main-breadcrumb">
    <h4 class="flex-grow-1 mb-0 breadcrumb-title fs-14">{{ $title }}</h4>
    <div class="flex-shrink-0">
        @if (!empty($breadcrumbItems))
            @include('layouts.includes.breadcrumb', [
                'items' => $breadcrumbItems,
            ])
        @endif
    </div>
</div>
