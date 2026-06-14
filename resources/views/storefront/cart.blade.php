@extends('storefront.layouts.app')
@php use App\Support\Money; @endphp
@section('title', 'Cart — Lord ICL')

@section('content')
    <div class="max-w-4xl mx-auto px-4 py-12">
        <h1 class="text-3xl font-serif font-bold mb-8">Your cart</h1>

        @if (empty($cart))
            <p class="text-stone-500">Your cart is empty. <a href="{{ url('/shop') }}" class="text-brand">Continue shopping →</a></p>
        @else
            <form method="POST" action="{{ route('cart.update') }}">
                @csrf
                <div class="bg-white rounded-2xl shadow divide-y">
                    @foreach ($cart as $id => $row)
                        <div class="flex items-center gap-4 p-4">
                            <div class="flex-1">
                                <p class="font-medium">{{ $row['name'] }}</p>
                                <p class="text-sm text-stone-500">{{ Money::display($row['price']) }} each</p>
                            </div>
                            <input type="number" name="qty[{{ $id }}]" value="{{ $row['qty'] }}" min="0" class="w-20 border rounded-lg px-3 py-1.5">
                            <div class="w-28 text-right font-medium">{{ Money::display($row['price'] * $row['qty']) }}</div>
                            <a href="#" onclick="event.preventDefault();document.getElementById('rm{{ $id }}').submit();" class="text-stone-400 hover:text-brand">✕</a>
                        </div>
                    @endforeach
                </div>
                <div class="flex justify-between items-center mt-4">
                    <button class="text-sm text-stone-500 hover:text-brand">Update cart</button>
                </div>
            </form>

            @foreach ($cart as $id => $row)
                <form id="rm{{ $id }}" method="POST" action="{{ route('cart.remove', $id) }}" class="hidden">@csrf</form>
            @endforeach

            <div class="bg-white rounded-2xl shadow p-6 mt-6 ml-auto max-w-sm space-y-2">
                <div class="flex justify-between"><span class="text-stone-500">Subtotal</span><span>{{ Money::display($totals['subtotal']) }}</span></div>
                <div class="flex justify-between"><span class="text-stone-500">GST</span><span>{{ Money::display($totals['tax']) }}</span></div>
                <div class="flex justify-between font-bold text-lg border-t pt-2"><span>Total</span><span class="text-brand">{{ Money::display($totals['total']) }}</span></div>
                <a href="{{ route('checkout') }}" class="block text-center bg-brand text-white px-8 py-3 rounded-full hover:bg-brand-dark transition mt-3">Checkout</a>
            </div>
        @endif
    </div>
@endsection
