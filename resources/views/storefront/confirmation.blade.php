@extends('storefront.layouts.app')
@php use App\Support\Money; @endphp
@section('title', 'Order confirmed — Lord ICL')

@section('content')
    <div class="max-w-2xl mx-auto px-4 py-16 text-center">
        <div class="text-6xl">✅</div>
        <h1 class="text-3xl font-serif font-bold mt-4">Thank you, {{ $order->customer_name }}!</h1>
        <p class="text-stone-500 mt-2">Your order <span class="font-semibold text-brand">{{ $order->order_no }}</span> has been received.</p>

        <div class="bg-white rounded-2xl shadow p-6 mt-8 text-left">
            <div class="divide-y">
                @foreach ($order->items as $item)
                    <div class="flex justify-between py-2 text-sm">
                        <span>{{ $item->name }} × {{ (int) $item->qty }}</span>
                        <span>{{ Money::format($item->line_total, $order->currency_code) }}</span>
                    </div>
                @endforeach
            </div>
            <div class="border-t mt-3 pt-3 flex justify-between font-bold">
                <span>Total ({{ $order->currency_code }})</span>
                <span class="text-brand">{{ Money::format($order->total, $order->currency_code) }}</span>
            </div>
            <p class="text-xs text-stone-400 mt-3">Status: {{ ucfirst($order->status) }} · Payment: {{ ucfirst($order->payment_status) }}</p>
        </div>

        <a href="{{ url('/shop') }}" class="inline-block mt-8 bg-brand text-white px-8 py-3 rounded-full hover:bg-brand-dark transition">Continue shopping</a>
    </div>
@endsection
