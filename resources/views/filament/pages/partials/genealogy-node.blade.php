@php
    $depth = $depth ?? 0;
    $hasKids = ! empty($node['children']);
@endphp
<li x-data="{ open: {{ $depth < 2 ? 'true' : 'false' }} }"
    x-on:icl-collapse.window="open = false"
    x-on:icl-expand.window="open = true">
    <div class="icl-card {{ $node['active'] ? '' : 'icl-inactive' }}"
         data-search="{{ strtolower($node['name'] . ' ' . $node['code']) }}"
         data-name="{{ $node['name'] }}"
         data-code="{{ $node['code'] }}"
         data-position="{{ $node['position'] }}"
         data-count="{{ $node['descendants'] }}"
         data-active="{{ $node['active'] ? '1' : '0' }}"
         data-url="{{ $node['url'] }}"
         @click="pick($el)">
        <div class="icl-ava">{{ $node['initial'] }}</div>
        <div class="icl-meta">
            <div class="icl-name">{{ $node['name'] }}</div>
            <div class="icl-code">{{ $node['code'] }}</div>
            @if ($node['position'])
                <span class="icl-pos">{{ $node['position'] }}</span>
            @endif
        </div>
        @if ($hasKids)
            <button type="button" class="icl-toggle"
                    @click.stop="open = ! open; $nextTick(() => centerOn($el.closest('.icl-card')))"
                    :title="open ? 'Collapse' : 'Expand'">
                <span x-text="open ? '–' : '+'"></span>
                <span class="icl-count">{{ $node['descendants'] }}</span>
            </button>
        @endif
    </div>

    @if ($hasKids)
        <ul x-show="open" x-transition.opacity.duration.150ms>
            @foreach ($node['children'] as $child)
                @include('filament.pages.partials.genealogy-node', ['node' => $child, 'depth' => $depth + 1])
            @endforeach
        </ul>
    @endif
</li>
