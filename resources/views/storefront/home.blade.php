@extends('storefront.layouts.app')
@php use App\Support\Translatable; use App\Support\Money; @endphp
@section('title', 'Lord ICL — Fine Jewellery')

@section('content')
    {{-- hero slider --}}
    @php $rtl = optional(\App\Models\Language::where('code', app()->getLocale())->first())->is_rtl; @endphp
    <section
        x-data="{
            active: 0,
            count: {{ count($slides) }},
            timer: null,
            rtl: {{ $rtl ? 'true' : 'false' }},
            start() { this.stop(); this.timer = setInterval(() => this.next(), 6000); },
            stop() { if (this.timer) clearInterval(this.timer); },
            next() { this.active = (this.active + 1) % this.count; },
            prev() { this.active = (this.active - 1 + this.count) % this.count; },
            go(i) { this.active = i; this.start(); },
        }"
        x-init="start()"
        @mouseenter="stop()" @mouseleave="start()"
        class="relative overflow-hidden text-white min-h-[460px] md:min-h-[560px]"
        role="region" aria-roledescription="carousel" aria-label="{{ tr('Featured collections') }}"
    >
        @foreach ($slides as $i => $slide)
            <div
                x-show="active === {{ $i }}"
                x-transition:enter="transition ease-out duration-700"
                x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
                @if (!$loop->first) x-cloak @endif
                class="absolute inset-0 flex items-center bg-gradient-to-br {{ $slide['gradient'] ?? 'from-brand-dark via-brand to-brand-dark' }}"
            >
                @if (!empty($slide['image']))
                    <img src="{{ $slide['image'] }}" alt="" class="absolute inset-0 w-full h-full object-cover opacity-40">
                    <div class="absolute inset-0 bg-gradient-to-br from-black/60 to-black/20"></div>
                @endif
                <div class="relative w-full max-w-7xl mx-auto px-4 py-16 text-center">
                    <p class="text-brand-gold tracking-[0.3em] uppercase text-sm">{{ tr($slide['eyebrow']) }}</p>
                    <h1 class="mt-4 text-4xl md:text-6xl font-serif font-bold max-w-3xl mx-auto">{{ tr($slide['title']) }}</h1>
                    <p class="mt-6 max-w-xl mx-auto text-stone-200">{{ tr($slide['subtitle']) }}</p>
                    <a href="{{ url($slide['link']) }}" class="inline-block mt-8 bg-brand-gold text-brand-dark font-semibold px-8 py-3 rounded-full hover:bg-brand-goldlight transition">{{ tr($slide['cta']) }}</a>
                </div>
            </div>
        @endforeach

        @if (count($slides) > 1)
            {{-- arrows (direction-aware for RTL) --}}
            <button type="button" @click="rtl ? next() : prev()" aria-label="{{ tr('Previous slide') }}"
                class="absolute top-1/2 -translate-y-1/2 left-4 rtl:left-auto rtl:right-4 z-10 w-10 h-10 grid place-items-center rounded-full bg-white/15 hover:bg-white/30 backdrop-blur transition">
                <span class="text-2xl leading-none">‹</span>
            </button>
            <button type="button" @click="rtl ? prev() : next()" aria-label="{{ tr('Next slide') }}"
                class="absolute top-1/2 -translate-y-1/2 right-4 rtl:right-auto rtl:left-4 z-10 w-10 h-10 grid place-items-center rounded-full bg-white/15 hover:bg-white/30 backdrop-blur transition">
                <span class="text-2xl leading-none">›</span>
            </button>

            {{-- dots --}}
            <div class="absolute bottom-5 inset-x-0 z-10 flex justify-center gap-2">
                @foreach ($slides as $i => $slide)
                    <button type="button" @click="go({{ $i }})" aria-label="{{ tr('Go to slide') }} {{ $i + 1 }}"
                        class="h-2 rounded-full transition-all duration-300"
                        :class="active === {{ $i }} ? 'w-6 bg-brand-gold' : 'w-2 bg-white/50 hover:bg-white/80'"></button>
                @endforeach
            </div>
        @endif
    </section>

    {{-- categories --}}
    <section class="max-w-7xl mx-auto px-4 py-16">
        <h2 class="text-2xl font-serif font-bold text-center mb-10">{{ tr('Shop by category') }}</h2>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-6">
            @foreach ($categories as $cat)
                <a href="{{ url('/shop?category='.$cat->id) }}" class="group block rounded-2xl bg-white shadow hover:shadow-xl transition overflow-hidden">
                    <div class="h-44 bg-gradient-to-br from-brand-goldlight to-brand-gold grid place-items-center text-brand-dark text-3xl font-serif font-bold">
                        {{ Translatable::pick($cat->name) }}
                    </div>
                    <div class="p-4 flex items-center justify-between">
                        <span class="font-medium">{{ Translatable::pick($cat->name) }}</span>
                        <span class="text-brand group-hover:translate-x-1 transition">→</span>
                    </div>
                </a>
            @endforeach
        </div>
    </section>

    {{-- featured products --}}
    <section class="bg-white py-16">
        <div class="max-w-7xl mx-auto px-4">
            <h2 class="text-2xl font-serif font-bold text-center mb-10">{{ tr('Featured pieces') }}</h2>
            @if ($featured->isEmpty())
                <p class="text-center text-stone-500">{{ tr('New pieces arriving soon.') }}</p>
            @else
                <div class="grid grid-cols-2 lg:grid-cols-4 gap-6">
                    @foreach ($featured as $product)
                        @include('storefront.partials.product-card', ['product' => $product])
                    @endforeach
                </div>
            @endif
        </div>
    </section>

    {{-- testimonials --}}
    @if ($testimonials->isNotEmpty())
        <section class="max-w-7xl mx-auto px-4 py-16">
            <h2 class="text-2xl font-serif font-bold text-center mb-10">{{ tr('What our customers say') }}</h2>
            <div class="grid md:grid-cols-3 gap-6">
                @foreach ($testimonials as $t)
                    <div class="bg-white rounded-2xl shadow p-6">
                        <div class="text-brand-gold">{!! str_repeat('★', (int) $t->rating) !!}</div>
                        <p class="mt-3 text-stone-600 italic">“{{ Translatable::pick($t->body) }}”</p>
                        <p class="mt-4 font-semibold">{{ $t->name }}</p>
                        <p class="text-sm text-stone-500">{{ $t->location }}</p>
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    {{-- latest posts --}}
    @if ($posts->isNotEmpty())
        <section class="bg-white py-16">
            <div class="max-w-7xl mx-auto px-4">
                <h2 class="text-2xl font-serif font-bold text-center mb-10">{{ tr('From our journal') }}</h2>
                <div class="grid md:grid-cols-3 gap-6">
                    @foreach ($posts as $post)
                        <a href="{{ url('/'.$post->type.'/'.$post->slug) }}" class="block bg-stone-50 rounded-2xl overflow-hidden shadow hover:shadow-lg transition">
                            <div class="h-40 bg-stone-200"></div>
                            <div class="p-5">
                                <span class="text-xs uppercase text-brand">{{ $post->type }}</span>
                                <h3 class="mt-1 font-serif font-bold">{{ Translatable::pick($post->title) }}</h3>
                                <p class="mt-2 text-sm text-stone-500">{{ Translatable::pick($post->excerpt) }}</p>
                            </div>
                        </a>
                    @endforeach
                </div>
            </div>
        </section>
    @endif
@endsection
