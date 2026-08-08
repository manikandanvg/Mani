@extends('storefront.layouts.app')
@php use App\Support\Money; @endphp
@section('title', 'Checkout — LORD JEWELLER')

@section('content')
    <div class="max-w-5xl mx-auto px-4 py-12 grid md:grid-cols-2 gap-10">
        <div>
            <h1 class="text-3xl font-serif font-bold mb-6">Checkout</h1>
            @if ($errors->any())
                <div class="bg-red-100 text-red-700 rounded-lg px-4 py-3 mb-6">
                    <ul class="list-disc list-inside text-sm">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
                </div>
            @endif
            <form method="POST" action="{{ route('checkout.place') }}" class="space-y-4">
                @csrf
                <input name="customer_name" value="{{ old('customer_name') }}" placeholder="Full name" class="w-full border rounded-lg px-4 py-2.5">
                <div class="grid grid-cols-2 gap-4">
                    <input name="email" value="{{ old('email') }}" placeholder="Email" class="border rounded-lg px-4 py-2.5">
                    <input name="phone" value="{{ old('phone') }}" placeholder="Phone" class="border rounded-lg px-4 py-2.5">
                </div>
                <input name="address" value="{{ old('address') }}" placeholder="Address" class="w-full border rounded-lg px-4 py-2.5">
                <div class="grid grid-cols-3 gap-4">
                    <input name="city" value="{{ old('city') }}" placeholder="City" class="border rounded-lg px-4 py-2.5">
                    <input name="pincode" value="{{ old('pincode') }}" placeholder="Pincode" class="border rounded-lg px-4 py-2.5">
                    <input name="country" value="{{ old('country', 'IN') }}" maxlength="2" placeholder="Country" class="border rounded-lg px-4 py-2.5">
                </div>
                <button class="bg-brand text-white px-8 py-3 rounded-full hover:bg-brand-dark hover:shadow-lg transition-all duration-300 w-full">{{ tr('Place order & pay') }}</button>
                <p class="text-xs text-stone-400 text-center">{{ tr('You will be taken to our secure payment page — Cards · UPI · Net Banking · Wallets.') }}</p>
            </form>
        </div>

        <div class="bg-white rounded-2xl shadow p-6 h-fit">
            <h2 class="font-serif font-bold text-lg mb-4">Order summary</h2>
            <div class="divide-y">
                @foreach ($cart as $row)
                    <div class="flex justify-between py-2 text-sm">
                        <span>{{ $row['name'] }} × {{ $row['qty'] }}</span>
                        <span>{{ Money::display($row['price'] * $row['qty']) }}</span>
                    </div>
                @endforeach
            </div>
            <div class="border-t mt-3 pt-3 space-y-1 text-sm">
                <div class="flex justify-between"><span class="text-stone-500">Subtotal</span><span>{{ Money::display($totals['subtotal']) }}</span></div>
                <div class="flex justify-between"><span class="text-stone-500">GST</span><span>{{ Money::display($totals['tax']) }}</span></div>
                <div class="flex justify-between font-bold text-lg pt-1"><span>Total</span><span class="text-brand">{{ Money::display($totals['total']) }}</span></div>
            </div>
        </div>
    </div>
@endsection
