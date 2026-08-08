@extends('storefront.layouts.app')
@php use App\Support\Translatable; use App\Support\Money; @endphp
@section('title', 'LORD JEWELLER — Fine Jewellery')

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
                x-transition:enter="transition ease-out duration-1000"
                x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
                x-transition:leave="transition ease-in duration-700"
                x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
                @if (!$loop->first) x-cloak @endif
                class="absolute inset-0 flex items-center bg-gradient-to-br {{ $slide['gradient'] ?? 'from-brand-dark via-brand to-brand-dark' }}"
            >
                @if (!empty($slide['image']))
                    {{-- locally stored photography with a soft golden glow --}}
                    <img src="{{ $slide['image'] }}" alt="" class="absolute inset-0 w-full h-full object-cover kenburns">
                    <div class="absolute inset-0 bg-gradient-to-b from-black/70 via-black/45 to-black/70"></div>
                    <div class="absolute inset-0 hero-glow pointer-events-none"
                         style="background: radial-gradient(ellipse 70% 55% at 50% 45%, rgba(230,173,70,.30), rgba(230,173,70,.10) 45%, transparent 70%);"></div>
                @endif
                <div class="relative w-full max-w-7xl mx-auto px-4 py-16 text-center">
                    <span class="inline-block h-px w-16 gold-rule mb-6"></span>
                    <p class="text-brand-gold tracking-luxe uppercase text-xs md:text-sm">{{ tr($slide['eyebrow']) }}</p>
                    <h1 class="mt-4 text-4xl md:text-6xl font-serif font-semibold max-w-3xl mx-auto leading-tight drop-shadow-[0_2px_18px_rgba(0,0,0,.45)]">{{ tr($slide['title']) }}</h1>
                    <p class="mt-6 max-w-xl mx-auto text-stone-200 font-light">{{ tr($slide['subtitle']) }}</p>
                    <a href="{{ url($slide['link']) }}"
                       class="inline-block mt-8 bg-brand-gold text-brand-deep font-semibold tracking-wide px-9 py-3.5 rounded-full shadow-lg shadow-black/30 hover:bg-brand-goldlight hover:shadow-brand-gold/30 hover:-translate-y-0.5 transition-all duration-300">{{ tr($slide['cta']) }}</a>
                </div>
            </div>
        @endforeach

        @if (count($slides) > 1)
            {{-- arrows (direction-aware for RTL) --}}
            <button type="button" @click="rtl ? next() : prev()" aria-label="{{ tr('Previous slide') }}"
                class="absolute top-1/2 -translate-y-1/2 left-4 rtl:left-auto rtl:right-4 z-10 w-10 h-10 grid place-items-center rounded-full bg-white/10 hover:bg-white/25 backdrop-blur ring-1 ring-white/25 transition">
                <span class="text-2xl leading-none">‹</span>
            </button>
            <button type="button" @click="rtl ? prev() : next()" aria-label="{{ tr('Next slide') }}"
                class="absolute top-1/2 -translate-y-1/2 right-4 rtl:right-auto rtl:left-4 z-10 w-10 h-10 grid place-items-center rounded-full bg-white/10 hover:bg-white/25 backdrop-blur ring-1 ring-white/25 transition">
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
        <div class="text-center mb-10 reveal">
            <p class="text-brand-gold tracking-luxe uppercase text-xs">{{ tr('Curated for you') }}</p>
            <h2 class="mt-2 text-3xl font-serif font-semibold">{{ tr('Shop by category') }}</h2>
            <span class="inline-block h-px w-20 gold-rule mt-4"></span>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-6">
            @foreach ($categories as $cat)
                <a href="{{ url('/shop?category='.$cat->id) }}"
                   class="group block rounded-2xl bg-white shadow hover:shadow-2xl overflow-hidden lift img-zoom reveal"
                   style="transition-delay: {{ ($loop->index % 3) * 120 }}ms">
                    <div class="relative h-52 overflow-hidden">
                        @if ($cat->imageUrl())
                            <img src="{{ $cat->imageUrl() }}" alt="{{ Translatable::pick($cat->name) }}" loading="lazy"
                                 class="absolute inset-0 w-full h-full object-cover">
                            <div class="absolute inset-0 bg-gradient-to-t from-black/60 via-black/10 to-transparent"></div>
                        @else
                            <div class="absolute inset-0 bg-gradient-to-br from-brand-goldlight to-brand-gold"></div>
                        @endif
                        <span class="absolute bottom-4 left-4 text-white text-2xl font-serif font-semibold drop-shadow">{{ Translatable::pick($cat->name) }}</span>
                    </div>
                    <div class="p-4 flex items-center justify-between">
                        <span class="font-medium">{{ Translatable::pick($cat->name) }}</span>
                        <span class="text-brand group-hover:translate-x-1.5 transition-transform duration-300">→</span>
                    </div>
                </a>
            @endforeach
        </div>
    </section>

    {{-- featured products --}}
    <section class="bg-white py-16">
        <div class="max-w-7xl mx-auto px-4">
            <div class="text-center mb-10 reveal">
                <p class="text-brand-gold tracking-luxe uppercase text-xs">{{ tr('Signature selection') }}</p>
                <h2 class="mt-2 text-3xl font-serif font-semibold">{{ tr('Featured pieces') }}</h2>
                <span class="inline-block h-px w-20 gold-rule mt-4"></span>
            </div>
            @if ($featured->isEmpty())
                <p class="text-center text-stone-500">{{ tr('New pieces arriving soon.') }}</p>
            @else
                <div class="grid grid-cols-2 lg:grid-cols-4 gap-6">
                    @foreach ($featured as $product)
                        <div class="reveal" style="transition-delay: {{ ($loop->index % 4) * 100 }}ms">
                            @include('storefront.partials.product-card', ['product' => $product])
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </section>

    {{-- testimonials --}}
    @if ($testimonials->isNotEmpty())
        <section class="max-w-7xl mx-auto px-4 py-16">
            <div class="text-center mb-10 reveal">
                <p class="text-brand-gold tracking-luxe uppercase text-xs">{{ tr('Voices of trust') }}</p>
                <h2 class="mt-2 text-3xl font-serif font-semibold">{{ tr('What our customers say') }}</h2>
                <span class="inline-block h-px w-20 gold-rule mt-4"></span>
            </div>
            <div class="grid md:grid-cols-3 gap-6">
                @foreach ($testimonials as $t)
                    <div class="bg-white rounded-2xl shadow p-7 lift reveal" style="transition-delay: {{ ($loop->index % 3) * 120 }}ms">
                        <div class="text-brand-gold tracking-widest">{!! str_repeat('★', (int) $t->rating) !!}</div>
                        <p class="mt-4 text-stone-600 italic font-light leading-relaxed">“{{ Translatable::pick($t->body) }}”</p>
                        <p class="mt-5 font-semibold">{{ $t->name }}</p>
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
                <div class="text-center mb-10 reveal">
                    <p class="text-brand-gold tracking-luxe uppercase text-xs">{{ tr('Stories & updates') }}</p>
                    <h2 class="mt-2 text-3xl font-serif font-semibold">{{ tr('From our journal') }}</h2>
                    <span class="inline-block h-px w-20 gold-rule mt-4"></span>
                </div>
                <div class="grid md:grid-cols-3 gap-6">
                    @foreach ($posts as $post)
                        <a href="{{ url('/'.$post->type.'/'.$post->slug) }}"
                           class="block bg-stone-50 rounded-2xl overflow-hidden shadow hover:shadow-xl lift img-zoom reveal"
                           style="transition-delay: {{ ($loop->index % 3) * 120 }}ms">
                            <div class="h-44 overflow-hidden bg-stone-200">
                                @if ($post->imageUrl())
                                    <img src="{{ $post->imageUrl() }}" alt="{{ Translatable::pick($post->title) }}" loading="lazy"
                                         class="w-full h-full object-cover">
                                @endif
                            </div>
                            <div class="p-5">
                                <span class="text-xs uppercase tracking-[0.18em] text-brand">{{ $post->type }}</span>
                                <h3 class="mt-1.5 font-serif font-bold text-lg leading-snug">{{ Translatable::pick($post->title) }}</h3>
                                <p class="mt-2 text-sm text-stone-500 font-light">{{ Translatable::pick($post->excerpt) }}</p>
                            </div>
                        </a>
                    @endforeach
                </div>
            </div>
        </section>
    @endif
@endsection
