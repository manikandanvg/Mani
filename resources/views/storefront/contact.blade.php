@extends('storefront.layouts.app')
@section('title', 'Contact — LORD JEWELLER')

@section('content')
    <div class="max-w-5xl mx-auto px-4 py-12">
        <div class="text-center mb-10 reveal revealed">
            <p class="text-brand-gold tracking-luxe uppercase text-xs">{{ tr('We are here for you') }}</p>
            <h1 class="mt-2 text-4xl font-serif font-semibold">{{ tr('Contact us') }}</h1>
            <span class="inline-block h-px w-20 gold-rule mt-4"></span>
        </div>

        @if ($errors->any())
            <div class="bg-red-100 text-red-700 rounded-lg px-4 py-3 mb-6">
                <ul class="list-disc list-inside text-sm">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
            </div>
        @endif

        <div class="grid md:grid-cols-5 gap-8">
            <div class="md:col-span-2 space-y-4">
                <div class="bg-white rounded-2xl shadow p-6 lift">
                    <h2 class="font-serif font-bold text-lg">{{ tr('Head Office') }}</h2>
                    <p class="mt-2 text-stone-600 font-light">{{ tr('Head Office, Coimbatore, India') }}</p>
                    <p class="mt-4 text-sm text-stone-500">{{ tr('Our concierge team responds within one business day.') }}</p>
                </div>
                <div class="bg-white rounded-2xl shadow p-6 lift">
                    <h2 class="font-serif font-bold text-lg">{{ tr('Visit a boutique') }}</h2>
                    <p class="mt-2 text-stone-600 font-light">{{ tr('Experience our collections in person at any of our stores.') }}</p>
                    <a href="{{ url('/stores') }}" class="inline-block mt-3 text-brand hover:text-brand-dark transition-colors">{{ tr('Store Locator') }} →</a>
                </div>
            </div>

            <form method="POST" action="{{ route('contact.submit') }}" class="md:col-span-3 space-y-4 bg-white rounded-2xl shadow p-7">
                @csrf
                <div class="grid sm:grid-cols-2 gap-4">
                    <div>
                        <label for="c-name" class="block text-sm text-stone-500 mb-1">{{ tr('Your name') }} *</label>
                        <input id="c-name" name="name" value="{{ old('name') }}" required
                               class="w-full border border-stone-200 rounded-lg px-4 py-2.5 focus:border-brand-gold focus:ring-brand-gold/40 transition">
                    </div>
                    <div>
                        <label for="c-email" class="block text-sm text-stone-500 mb-1">{{ tr('Email') }} *</label>
                        <input id="c-email" type="email" name="email" value="{{ old('email') }}" required
                               class="w-full border border-stone-200 rounded-lg px-4 py-2.5 focus:border-brand-gold focus:ring-brand-gold/40 transition">
                    </div>
                </div>
                <div>
                    <label for="c-phone" class="block text-sm text-stone-500 mb-1">{{ tr('Phone number') }} *</label>
                    <input id="c-phone" type="tel" name="phone" value="{{ old('phone') }}" required
                           placeholder="+91 98765 43210"
                           class="w-full border border-stone-200 rounded-lg px-4 py-2.5 focus:border-brand-gold focus:ring-brand-gold/40 transition">
                </div>
                <div>
                    <label for="c-message" class="block text-sm text-stone-500 mb-1">{{ tr('How can we help?') }} *</label>
                    <textarea id="c-message" name="message" rows="5" required
                              class="w-full border border-stone-200 rounded-lg px-4 py-2.5 focus:border-brand-gold focus:ring-brand-gold/40 transition">{{ old('message') }}</textarea>
                </div>
                <button class="bg-brand text-white px-9 py-3 rounded-full hover:bg-brand-dark hover:shadow-lg transition-all duration-300">{{ tr('Send message') }}</button>
            </form>
        </div>
    </div>
@endsection
