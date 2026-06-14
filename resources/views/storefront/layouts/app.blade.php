@php
    use App\Models\Language;
    use App\Models\Currency;
    use App\Models\Category;
    use App\Models\CmsPage;
    use App\Support\Translatable;

    $languages = Language::where('is_active', true)->orderBy('sort')->get();
    $currencies = Currency::where('is_active', true)->get();
    $shopCats = Category::where('domain', 'ecommerce')->whereNull('parent_id')->where('is_active', true)->get();
    $policies = CmsPage::published()->where('group', 'policy')->orderBy('sort')->get();
    $cartCount = collect(session('cart', []))->sum('qty');
    $curLang = $languages->firstWhere('code', app()->getLocale());
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ optional($curLang)->is_rtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Lord ICL — Fine Jewellery')</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: { extend: { colors: {
                brand: { DEFAULT: '#ab222f', dark: '#8a1b26', gold: '#e6ad46', goldlight: '#f3d79a' }
            }, fontFamily: { serif: ['Georgia', 'serif'] } } }
        }
    </script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    @stack('head')
</head>
<body class="bg-stone-50 text-stone-800 antialiased flex flex-col min-h-screen">

    {{-- top bar --}}
    <div class="bg-brand-dark text-white text-xs">
        <div class="max-w-7xl mx-auto px-4 py-2 flex items-center justify-between">
            <span class="hidden sm:block">{{ tr('Global fine jewellery · gold · silver · accessories') }}</span>
            <div class="flex items-center gap-4">
                <form method="GET" action="{{ url('/lang') }}" x-data>
                    <select name="code" onchange="this.form.submit()"
                        class="bg-transparent border border-white/30 rounded px-2 py-0.5 text-white">
                        @foreach ($languages as $l)
                            <option value="{{ $l->code }}" @selected(app()->getLocale() === $l->code) class="text-black">{{ $l->native_name ?: $l->name }}</option>
                        @endforeach
                    </select>
                </form>
                <form method="GET" action="{{ url('/currency') }}">
                    <select name="code" onchange="this.form.submit()"
                        class="bg-transparent border border-white/30 rounded px-2 py-0.5 text-white">
                        @foreach ($currencies as $c)
                            <option value="{{ $c->code }}" @selected(\App\Support\Money::current() === $c->code) class="text-black">{{ $c->code }}</option>
                        @endforeach
                    </select>
                </form>
            </div>
        </div>
    </div>

    {{-- header / nav --}}
    <header class="bg-white shadow-sm sticky top-0 z-30" x-data="{ open: false }">
        <div class="max-w-7xl mx-auto px-4 flex items-center justify-between h-20">
            <a href="{{ url('/') }}" class="flex items-center gap-2">
                <span class="text-2xl font-serif font-bold text-brand">Lord<span class="text-brand-gold">ICL</span></span>
            </a>

            <nav class="hidden lg:flex items-center gap-7 text-sm font-medium">
                <a href="{{ url('/') }}" class="hover:text-brand">{{ tr('Home') }}</a>
                <div class="relative" x-data="{ s: false }" @mouseenter="s=true" @mouseleave="s=false">
                    <a href="{{ url('/shop') }}" class="hover:text-brand">{{ tr('Shop') }} ▾</a>
                    <div x-show="s" x-cloak class="absolute left-0 mt-2 w-44 bg-white shadow-lg rounded-md py-2">
                        <a href="{{ url('/shop') }}" class="block px-4 py-2 hover:bg-stone-100">{{ tr('All') }}</a>
                        @foreach ($shopCats as $cat)
                            <a href="{{ url('/shop?category='.$cat->id) }}" class="block px-4 py-2 hover:bg-stone-100">{{ Translatable::pick($cat->name) }}</a>
                        @endforeach
                    </div>
                </div>
                <a href="{{ url('/about') }}" class="hover:text-brand">{{ tr('About') }}</a>
                <a href="{{ url('/services') }}" class="hover:text-brand">{{ tr('Services') }}</a>
                <a href="{{ url('/news') }}" class="hover:text-brand">{{ tr('News') }}</a>
                <a href="{{ url('/blog') }}" class="hover:text-brand">{{ tr('Blog') }}</a>
                <a href="{{ url('/stores') }}" class="hover:text-brand">{{ tr('Stores') }}</a>
                <a href="{{ url('/contact') }}" class="hover:text-brand">{{ tr('Contact') }}</a>
            </nav>

            <div class="flex items-center gap-4">
                <a href="{{ url('/cart') }}" class="relative">
                    <span class="text-2xl">🛒</span>
                    @if ($cartCount > 0)
                        <span class="absolute -top-2 -right-2 bg-brand text-white text-xs rounded-full w-5 h-5 grid place-items-center">{{ $cartCount }}</span>
                    @endif
                </a>
                <button class="lg:hidden text-2xl" @click="open=!open">☰</button>
            </div>
        </div>
        {{-- mobile nav --}}
        <div x-show="open" x-cloak class="lg:hidden border-t px-4 py-3 space-y-2 text-sm">
            @foreach (['/'=>'Home','/shop'=>'Shop','/about'=>'About','/services'=>'Services','/news'=>'News','/blog'=>'Blog','/stores'=>'Stores','/contact'=>'Contact'] as $href=>$label)
                <a href="{{ url($href) }}" class="block py-1">{{ tr($label) }}</a>
            @endforeach
        </div>
    </header>

    @if (session('success'))
        <div class="max-w-7xl mx-auto px-4 mt-4">
            <div class="bg-green-100 text-green-800 rounded-lg px-4 py-3">{{ session('success') }}</div>
        </div>
    @endif

    <main class="flex-1">
        @yield('content')
    </main>

    {{-- footer --}}
    <footer class="bg-stone-900 text-stone-300 mt-16">
        <div class="max-w-7xl mx-auto px-4 py-12 grid grid-cols-2 md:grid-cols-4 gap-8 text-sm">
            <div>
                <span class="text-xl font-serif font-bold text-white">Lord<span class="text-brand-gold">ICL</span></span>
                <p class="mt-3 text-stone-400">{{ tr('Fine jewellery crafted for a global family. Gold, silver & accessories.') }}</p>
            </div>
            <div>
                <h4 class="text-white font-semibold mb-3">{{ tr('Explore') }}</h4>
                <ul class="space-y-2">
                    <li><a href="{{ url('/shop') }}" class="hover:text-brand-gold">{{ tr('Shop') }}</a></li>
                    <li><a href="{{ url('/about') }}" class="hover:text-brand-gold">{{ tr('About') }}</a></li>
                    <li><a href="{{ url('/services') }}" class="hover:text-brand-gold">{{ tr('Services') }}</a></li>
                    <li><a href="{{ url('/stores') }}" class="hover:text-brand-gold">{{ tr('Store Locator') }}</a></li>
                    <li><a href="{{ url('/faq') }}" class="hover:text-brand-gold">{{ tr('FAQ') }}</a></li>
                </ul>
            </div>
            <div>
                <h4 class="text-white font-semibold mb-3">{{ tr('Policies') }}</h4>
                <ul class="space-y-2">
                    @forelse ($policies as $p)
                        <li><a href="{{ url('/page/'.$p->slug) }}" class="hover:text-brand-gold">{{ Translatable::pick($p->title) }}</a></li>
                    @empty
                        <li class="text-stone-500">—</li>
                    @endforelse
                </ul>
            </div>
            <div>
                <h4 class="text-white font-semibold mb-3">{{ tr('Contact') }}</h4>
                <p class="text-stone-400">{{ tr('Head Office, Coimbatore, India') }}</p>
                <a href="{{ url('/contact') }}" class="inline-block mt-3 text-brand-gold">{{ tr('Get in touch') }} →</a>

                <h4 class="text-white font-semibold mt-6 mb-3">{{ tr('Get the app') }}</h4>
                <div class="flex flex-col gap-2">
                    {{-- Google Play --}}
                    <a href="{{ config('services.app.android', '#') }}" target="_blank" rel="noopener"
                       aria-label="{{ tr('Get it on Google Play') }}"
                       class="flex items-center gap-3 rounded-lg border border-white/20 bg-black/40 px-3 py-2 hover:bg-black/60 hover:border-brand-gold transition">
                        <svg viewBox="0 0 512 512" class="w-6 h-6 shrink-0" aria-hidden="true">
                            <path fill="#00d4ff" d="M48 28.5C42 35 39 44 39 56v400c0 12 3 21 9 27.5l1.4 1.3L268 266.5v-21L49.4 27.2z"/>
                            <path fill="#ffce00" d="M345 339.5l-77-77v-21l77.1-77 1.7 1L437 217c26 14.7 26 38.7 0 53.5l-90.3 51.2z"/>
                            <path fill="#00f076" d="M346.7 338.5L268 256 48 477c8.6 9.1 22.7 10.2 38.7 1.1z"/>
                            <path fill="#f63448" d="M346.7 173.5L86.7 33.9C70.7 24.8 56.6 25.9 48 35l220 221z"/>
                        </svg>
                        <span class="leading-tight">
                            <span class="block text-[10px] text-stone-400 uppercase tracking-wide">{{ tr('Get it on') }}</span>
                            <span class="block text-sm font-semibold text-white">Google Play</span>
                        </span>
                    </a>
                    {{-- App Store --}}
                    <a href="{{ config('services.app.ios', '#') }}" target="_blank" rel="noopener"
                       aria-label="{{ tr('Download on the App Store') }}"
                       class="flex items-center gap-3 rounded-lg border border-white/20 bg-black/40 px-3 py-2 hover:bg-black/60 hover:border-brand-gold transition">
                        <svg viewBox="0 0 384 512" class="w-6 h-6 shrink-0 fill-white" aria-hidden="true">
                            <path d="M318.7 268.7c-.2-36.7 16.4-64.4 50-84.8-18.8-26.9-47.2-41.7-84.7-44.6-35.5-2.8-74.3 20.7-88.5 20.7-15 0-49.4-19.7-76.4-19.7C63.3 141.2 4 184.8 4 273.5q0 39.3 14.4 81.2c12.8 36.7 59 126.7 107.2 125.2 25.2-.6 43-17.9 75.8-17.9 31.8 0 48.3 17.9 76.4 17.9 48.6-.7 90.4-82.5 102.6-119.3-65.2-30.7-61.7-90-61.7-91.9zm-56.6-164.2c27.3-32.4 24.8-61.9 24-72.5-24.1 1.4-52 16.4-67.9 34.9-17.5 19.8-27.8 44.3-25.6 71.9 26.1 2 49.9-11.4 69.5-34.3z"/>
                        </svg>
                        <span class="leading-tight">
                            <span class="block text-[10px] text-stone-400 uppercase tracking-wide">{{ tr('Download on the') }}</span>
                            <span class="block text-sm font-semibold text-white">App Store</span>
                        </span>
                    </a>
                </div>
            </div>
        </div>
        <div class="border-t border-white/10 text-center text-xs text-stone-500 py-4">
            © {{ date('Y') }} Lord ICL. All rights reserved.
        </div>
    </footer>

    <style>[x-cloak]{display:none!important}</style>
    <script src="{{ asset('js/enter-as-tab.js') }}" defer></script>
</body>
</html>
