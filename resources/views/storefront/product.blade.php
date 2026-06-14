@extends('storefront.layouts.app')
@php use App\Support\Translatable; use App\Support\Money; @endphp
@section('title', Translatable::pick($product->name).' — Lord ICL')

@section('content')
    <div class="max-w-7xl mx-auto px-4 py-10">
        <a href="{{ url('/shop') }}" class="text-sm text-stone-500 hover:text-brand">← {{ tr('Back to shop') }}</a>
        <div class="grid md:grid-cols-2 gap-10 mt-4">
            <div class="rounded-2xl bg-gradient-to-br from-stone-100 to-stone-200 h-96 grid place-items-center text-8xl">
                {{ $product->material === 'silver' ? '💍' : ($product->material === 'accessory' ? '🎁' : '💛') }}
            </div>
            <div>
                @php $b = $product->priceBreakup(); @endphp
                <span class="text-xs uppercase text-stone-400">{{ $product->material }} · {{ optional($product->category)->slug }}</span>
                <h1 class="text-3xl font-serif font-bold mt-1">{{ Translatable::pick($product->name) }}</h1>

                @if ($b['total'] > 0)
                    <p class="text-3xl font-serif text-brand mt-4">{{ Money::display($b['total']) }}
                        <span class="text-sm text-stone-400 font-sans">{{ tr('incl. GST') }}</span></p>
                @else
                    <p class="text-2xl font-serif text-brand mt-4">{{ tr('Price on request') }}</p>
                @endif

                {{-- price breakup --}}
                @if ($b['total'] > 0)
                    <div class="mt-6 rounded-2xl border border-stone-200 bg-white overflow-hidden">
                        <div class="px-4 py-2.5 bg-stone-50 border-b border-stone-200 flex items-center justify-between">
                            <span class="text-sm font-semibold">{{ tr('Price breakup') }}</span>
                            @if ($b['has_rate'])
                                <span class="text-xs text-stone-500">{{ tr('Live rate') }}: {{ Money::display($b['rate']) }}/g</span>
                            @endif
                        </div>
                        <table class="w-full text-sm">
                            <tbody class="divide-y divide-stone-100">
                                @if ($b['has_rate'])
                                    <tr>
                                        <td class="px-4 py-2 text-stone-600">
                                            {{ tr('Metal value') }}
                                            <span class="text-stone-400">({{ rtrim(rtrim(number_format($b['weight'], 4), '0'), '.') }} g × {{ Money::display($b['rate']) }})</span>
                                        </td>
                                        <td class="px-4 py-2 text-right tabular-nums">{{ Money::display($b['metal_value']) }}</td>
                                    </tr>
                                    @if ($b['making'] > 0)
                                        <tr>
                                            <td class="px-4 py-2 text-stone-600">{{ tr('Making') }} <span class="text-stone-400">({{ rtrim(rtrim(number_format($b['making_pct'], 3), '0'), '.') }}%)</span></td>
                                            <td class="px-4 py-2 text-right tabular-nums">{{ Money::display($b['making']) }}</td>
                                        </tr>
                                    @endif
                                    @if ($b['wastage'] > 0)
                                        <tr>
                                            <td class="px-4 py-2 text-stone-600">{{ tr('Wastage') }} <span class="text-stone-400">({{ rtrim(rtrim(number_format($b['wastage_pct'], 3), '0'), '.') }}%)</span></td>
                                            <td class="px-4 py-2 text-right tabular-nums">{{ Money::display($b['wastage']) }}</td>
                                        </tr>
                                    @endif
                                    @if ($b['hallmark'] > 0)
                                        <tr>
                                            <td class="px-4 py-2 text-stone-600">{{ tr('Hallmark') }} <span class="text-stone-400">({{ rtrim(rtrim(number_format($b['hallmark_pct'], 3), '0'), '.') }}%)</span></td>
                                            <td class="px-4 py-2 text-right tabular-nums">{{ Money::display($b['hallmark']) }}</td>
                                        </tr>
                                    @endif
                                @endif
                                <tr class="bg-stone-50/60">
                                    <td class="px-4 py-2 font-medium">{{ tr('Subtotal') }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums font-medium">{{ Money::display($b['subtotal']) }}</td>
                                </tr>
                                @if ($b['gst'] > 0)
                                    <tr>
                                        <td class="px-4 py-2 text-stone-600">{{ tr('GST') }} <span class="text-stone-400">({{ rtrim(rtrim(number_format($b['gst_pct'], 3), '0'), '.') }}%)</span></td>
                                        <td class="px-4 py-2 text-right tabular-nums">{{ Money::display($b['gst']) }}</td>
                                    </tr>
                                @endif
                                <tr class="bg-brand/5">
                                    <td class="px-4 py-3 font-bold text-brand">{{ tr('Total') }}</td>
                                    <td class="px-4 py-3 text-right tabular-nums font-bold text-brand">{{ Money::display($b['total']) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    @if ($b['has_rate'])
                        <p class="mt-2 text-xs text-stone-400">{{ tr('Price updates with the live metal rate and may vary at checkout.') }}</p>
                    @endif
                @endif

                <dl class="mt-6 grid grid-cols-2 gap-3 text-sm">
                    @if ($product->purity)<div><dt class="text-stone-400">{{ tr('Purity') }}</dt><dd>{{ $product->purity }}</dd></div>@endif
                    @if ($product->stone_weight)<div><dt class="text-stone-400">{{ tr('Stone weight') }}</dt><dd>{{ rtrim(rtrim(number_format($product->stone_weight, 4), '0'), '.') }} g</dd></div>@endif
                </dl>

                @if (Translatable::pick($product->description))
                    <p class="mt-6 text-stone-600">{{ Translatable::pick($product->description) }}</p>
                @endif

                <form method="POST" action="{{ route('cart.add', $product->id) }}" class="mt-8 flex items-center gap-3">
                    @csrf
                    <input type="number" name="qty" value="1" min="1" class="w-20 border rounded-lg px-3 py-2">
                    <button class="bg-brand text-white px-8 py-3 rounded-full hover:bg-brand-dark transition">{{ tr('Add to cart') }}</button>
                </form>
            </div>
        </div>

        @if ($related->isNotEmpty())
            <h2 class="text-xl font-serif font-bold mt-16 mb-6">You may also like</h2>
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-6">
                @foreach ($related as $product)
                    @include('storefront.partials.product-card', ['product' => $product])
                @endforeach
            </div>
        @endif
    </div>
@endsection
