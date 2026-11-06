@php
    $items = $items ?? [];
@endphp

@if (!empty($items))
    <nav aria-label="breadcrumb">
        <ol class="justify-content-end mb-0 breadcrumb">
            @foreach ($items as $item)
                @php
                    $isLast = $loop->last;
                @endphp
                <li class="breadcrumb-item{{ $isLast ? ' active' : '' }}"
                    @if ($isLast) aria-current="page" @endif>
                    @if (!$isLast && !empty($item['url']))
                        <a href="{{ $item['url'] }}">{{ $item['label'] ?? '' }}</a>
                    @else
                        {{ $item['label'] ?? '' }}
                    @endif
                </li>
            @endforeach
        </ol>
    </nav>
@endif
