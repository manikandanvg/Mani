@extends('storefront.layouts.app')
@section('title', 'Digi Market — LORD JEWELLER')

@section('content')
    <div class="max-w-xl mx-auto px-4 py-16">
        <div class="bg-white rounded-2xl shadow p-8 text-center">
            @if (($error ?? null) || $purchase->status !== 'paid')
                <h1 class="text-2xl font-serif font-bold text-red-700">{{ tr('Payment not completed') }}</h1>
                <p class="text-stone-500 mt-3">{{ $error ?? tr('No metal was credited. You can close this window and try again from the app.') }}</p>
            @else
                <div class="text-5xl mb-3">✅</div>
                <h1 class="text-2xl font-serif font-bold">{{ tr('Payment successful') }}</h1>
                <p class="text-stone-600 mt-3">
                    {{ number_format((float) $purchase->grams, 4) }} g {{ tr('of Digi') }} {{ ucfirst($purchase->metal) }} {{ tr('has been credited to your wallet.') }}
                </p>
            @endif
            <p class="text-stone-400 text-sm mt-6">{{ tr('You can close this window and return to the app.') }}</p>
        </div>
    </div>
@endsection
