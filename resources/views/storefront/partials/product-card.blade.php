@php use App\Support\Translatable; use App\Support\Money; @endphp
<div class="group bg-white rounded-2xl shadow hover:shadow-xl transition overflow-hidden flex flex-col">
    <a href="{{ url('/product/'.$product->id) }}" class="block">
        <div class="h-48 bg-gradient-to-br from-stone-100 to-stone-200 grid place-items-center text-5xl">
            {{ $product->material === 'silver' ? '💍' : ($product->material === 'accessory' ? '🎁' : '💛') }}
        </div>
    </a>
    <div class="p-4 flex flex-col flex-1">
        <span class="text-xs uppercase text-stone-400">{{ $product->material }}</span>
        <a href="{{ url('/product/'.$product->id) }}" class="font-medium hover:text-brand line-clamp-2">{{ Translatable::pick($product->name) }}</a>
        @php $cardTotal = $product->displayTotal(); @endphp
        <div class="mt-auto pt-3 flex items-center justify-between">
            <span class="font-serif font-bold text-brand">{{ $cardTotal > 0 ? Money::display($cardTotal) : tr('On request') }}</span>
            <form method="POST" action="{{ route('cart.add', $product->id) }}">
                @csrf
                <button class="bg-brand text-white text-sm px-3 py-1.5 rounded-full hover:bg-brand-dark transition">{{ tr('Add') }}</button>
            </form>
        </div>
    </div>
</div>
