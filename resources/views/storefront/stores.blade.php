@extends('storefront.layouts.app')
@section('title', 'Store Locator — Lord ICL')

@php
    $geo = $branches->filter(fn ($b) => $b->latitude && $b->longitude && (float) $b->latitude != 0);
    $points = $geo->map(fn ($b) => [
        'id' => $b->id,
        'name' => $b->name,
        'addr' => trim(($b->address ?? '') . ($b->city ? ', ' . $b->city : '') . ($b->pincode ? ' - ' . $b->pincode : ''), ', '),
        'phone' => $b->phone,
        'lat' => (float) $b->latitude,
        'lng' => (float) $b->longitude,
    ])->values();
@endphp

@push('head')
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
@endpush

@section('content')
    <div class="max-w-7xl mx-auto px-4 py-8" x-data="{ q: '' }">
        <h1 class="text-3xl md:text-4xl font-serif font-bold">{{ tr('Store locator') }}</h1>
        <p class="text-stone-500 mt-1 mb-6">{{ $branches->count() }} {{ tr('stores') }} · {{ $geo->count() }} {{ tr('on the map') }}</p>

        <div class="grid lg:grid-cols-[360px_1fr] gap-4 rounded-2xl overflow-hidden border border-stone-200 shadow-sm">
            {{-- sidebar list --}}
            <aside class="bg-white flex flex-col" style="height:72vh">
                <div class="p-3 border-b border-stone-100">
                    <input x-model="q" type="search" placeholder="{{ tr('Search store, city or pincode') }}"
                        class="w-full border border-stone-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-brand">
                </div>
                <div class="overflow-y-auto flex-1 divide-y divide-stone-100">
                    @foreach ($branches as $b)
                        @php $hasGeo = $b->latitude && $b->longitude && (float) $b->latitude != 0; @endphp
                        <button type="button"
                            @if ($hasGeo) onclick="iclFlyTo({{ $b->id }})" @endif
                            x-show="[@js(strtolower($b->name)), @js(strtolower($b->city ?? '')), @js((string) $b->pincode)].some(s => s.includes(q.toLowerCase()))"
                            class="w-full text-left px-4 py-3 hover:bg-stone-50 transition {{ $hasGeo ? '' : 'opacity-70' }}">
                            <div class="font-medium text-stone-800">{{ $b->name }}</div>
                            <div class="text-xs text-stone-500 mt-0.5">{{ $b->address }}@if($b->city), {{ $b->city }}@endif @if($b->pincode)- {{ $b->pincode }}@endif</div>
                            <div class="flex items-center gap-3 mt-1">
                                @if ($b->phone)<span class="text-xs text-stone-500">☎ {{ $b->phone }}</span>@endif
                                @if ($hasGeo)
                                    <span class="text-xs text-brand">{{ tr('Show on map') }} →</span>
                                @else
                                    <span class="text-xs text-stone-400">{{ tr('Location not set') }}</span>
                                @endif
                            </div>
                        </button>
                    @endforeach
                </div>
            </aside>

            {{-- map --}}
            <div id="icl-map" style="height:72vh; min-height:420px; background:#eef2f4"></div>
        </div>
    </div>

    <script>
        (function () {
            var points = @json($points);
            var map, markers = {};

            function init() {
                map = L.map('icl-map', { scrollWheelZoom: false }).setView([20.5937, 78.9629], 5); // India
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    maxZoom: 19, attribution: '© OpenStreetMap'
                }).addTo(map);

                var group = [];
                points.forEach(function (p) {
                    var m = L.circleMarker([p.lat, p.lng], {
                        radius: 8, color: '#ab222f', fillColor: '#ab222f', fillOpacity: 0.85, weight: 2
                    }).addTo(map);
                    m.bindPopup('<strong>' + p.name + '</strong><br>' + (p.addr || '') +
                        (p.phone ? '<br>☎ ' + p.phone : '') +
                        '<br><a target="_blank" href="https://www.google.com/maps?q=' + p.lat + ',' + p.lng + '">Directions →</a>');
                    markers[p.id] = m;
                    group.push([p.lat, p.lng]);
                });

                if (group.length === 1) { map.setView(group[0], 14); }
                else if (group.length > 1) { map.fitBounds(group, { padding: [40, 40] }); }
            }

            window.iclFlyTo = function (id) {
                var m = markers[id];
                if (!m) return;
                map.flyTo(m.getLatLng(), 15, { duration: 0.8 });
                m.openPopup();
                document.getElementById('icl-map').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            };

            if (document.readyState !== 'loading') init();
            else document.addEventListener('DOMContentLoaded', init);
        })();
    </script>
@endsection
