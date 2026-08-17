@extends('storefront.layouts.app')
@section('title', 'Digi Market — LORD JEWELLER')

@section('content')
    <div class="max-w-xl mx-auto px-4 py-16">
        <div class="bg-white rounded-2xl shadow p-8 text-center">
            <p class="text-brand-gold tracking-[0.2em] uppercase text-xs">{{ tr('Secure payment') }}</p>
            <h1 class="text-2xl font-serif font-bold mt-2">{{ tr('Buy Digi') }} {{ ucfirst($purchase->metal) }}</h1>

            <div class="border-t border-b border-stone-100 my-6 py-4 text-sm text-left space-y-1">
                <div class="flex justify-between"><span class="text-stone-600">{{ ucfirst($purchase->metal) }}</span><span class="font-semibold">{{ number_format((float) $purchase->grams, 4) }} g</span></div>
                <div class="flex justify-between"><span class="text-stone-600">{{ tr('Rate') }}</span><span>₹{{ \App\Support\Money::group((float) $purchase->rate) }} / g</span></div>
            </div>

            <div class="flex justify-between items-baseline mb-6">
                <span class="font-medium">{{ tr('Amount payable') }}</span>
                <span class="text-3xl font-serif font-bold text-brand">₹{{ \App\Support\Money::group((float) $purchase->amount) }}</span>
            </div>

            @if ($razorpayKey && $purchase->razorpay_order_id)
                <button id="rzp-pay" class="w-full bg-brand text-white font-semibold px-8 py-3 rounded-full hover:bg-brand-dark transition">
                    {{ tr('Pay now') }}
                </button>
                <p class="text-xs text-stone-400 mt-3">{{ tr('Cards · UPI · Net Banking · Wallets — powered by Razorpay') }}</p>

                <form id="verify-form" method="POST" action="{{ route('digigold.verify') }}" class="hidden">
                    @csrf
                    <input type="hidden" name="purchase_id" value="{{ $purchase->id }}">
                    <input type="hidden" name="razorpay_payment_id" id="rzp_payment_id">
                    <input type="hidden" name="razorpay_order_id" id="rzp_order_id">
                    <input type="hidden" name="razorpay_signature" id="rzp_signature">
                </form>

                <script src="https://checkout.razorpay.com/v1/checkout.js"></script>
                <script>
                    (function () {
                        var options = {
                            key: @json($razorpayKey),
                            order_id: @json($purchase->razorpay_order_id),
                            amount: {{ $amountPaise }},
                            currency: 'INR',
                            name: 'LORD JEWELLER',
                            image: @json(asset('images/logo.png')),
                            description: 'Digi {{ ucfirst($purchase->metal) }} {{ number_format((float) $purchase->grams, 4) }} g',
                            prefill: {
                                name: @json($purchase->member?->name),
                                contact: @json($purchase->member?->phone)
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
                        rzp.open();
                    })();
                </script>
            @else
                <p class="text-red-600">{{ tr('Payment gateway is unavailable right now. Please try again later.') }}</p>
            @endif
        </div>
    </div>
@endsection
