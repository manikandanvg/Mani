@extends('storefront.layouts.app')
@php use App\Support\Money; @endphp
@section('title', 'Pay for order ' . $order->order_no . ' — LORD JEWELLER')

@section('content')
    <div class="max-w-xl mx-auto px-4 py-16">
        @if (session('error'))
            <div class="bg-red-100 text-red-800 rounded-lg px-4 py-3 mb-6">{{ session('error') }}</div>
        @endif

        <div class="bg-white rounded-2xl shadow p-8 text-center">
            <p class="text-brand-gold tracking-[0.2em] uppercase text-xs">{{ tr('Secure payment') }}</p>
            <h1 class="text-2xl font-serif font-bold mt-2">{{ tr('Complete your payment') }}</h1>
            <p class="text-stone-500 mt-1">{{ tr('Order') }} <span class="font-semibold text-brand">{{ $order->order_no }}</span></p>

            <div class="border-t border-b border-stone-100 my-6 py-4 text-left">
                @foreach ($order->items as $item)
                    <div class="flex justify-between py-1 text-sm">
                        <span class="text-stone-600">{{ $item->name }} × {{ (int) $item->qty }}</span>
                        <span>{{ Money::format($item->line_total, 'INR') }}</span>
                    </div>
                @endforeach
            </div>

            <div class="flex justify-between items-baseline mb-6">
                <span class="font-medium">{{ tr('Amount payable') }}</span>
                <span class="text-3xl font-serif font-bold text-brand">{{ Money::format($order->total, 'INR') }}</span>
            </div>

            @if ($razorpayKey && $order->razorpay_order_id)
                <button id="rzp-pay" class="w-full bg-brand text-white font-semibold px-8 py-3 rounded-full hover:bg-brand-dark transition">
                    {{ tr('Pay now') }}
                </button>
                <p class="text-xs text-stone-400 mt-3">{{ tr('Cards · UPI · Net Banking · Wallets — powered by Razorpay') }}</p>

                <form id="verify-form" method="POST" action="{{ route('payment.verify') }}" class="hidden">
                    @csrf
                    <input type="hidden" name="order_no" value="{{ $order->order_no }}">
                    <input type="hidden" name="razorpay_payment_id" id="rzp_payment_id">
                    <input type="hidden" name="razorpay_order_id" id="rzp_order_id">
                    <input type="hidden" name="razorpay_signature" id="rzp_signature">
                </form>

                <script src="https://checkout.razorpay.com/v1/checkout.js"></script>
                <script>
                    (function () {
                        var options = {
                            key: @json($razorpayKey),
                            order_id: @json($order->razorpay_order_id),
                            amount: {{ $amountPaise }},
                            currency: 'INR',
                            name: 'LORD JEWELLER',
                            image: @json(asset('images/logo.png')),
                            description: 'Order {{ $order->order_no }}',
                            prefill: {
                                name: @json($order->customer_name),
                                email: @json($order->email),
                                contact: @json($order->phone)
                            },
                            theme: { color: '#ab222f' },
                            handler: function (response) {
                                document.getElementById('rzp_payment_id').value = response.razorpay_payment_id;
                                document.getElementById('rzp_order_id').value = response.razorpay_order_id;
                                document.getElementById('rzp_signature').value = response.razorpay_signature;
                                document.getElementById('verify-form').submit();
                            }
                        };
                        var rzp = new Razorpay(options);
                        document.getElementById('rzp-pay').addEventListener('click', function () { rzp.open(); });
                    })();
                </script>
            @else
                <p class="text-red-600">{{ tr('Payment gateway is unavailable right now. Please contact us to complete your order.') }}</p>
            @endif
        </div>
    </div>
@endsection
