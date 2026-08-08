@php use App\Support\Translatable; use App\Support\Money; @endphp
<div class="group bg-white rounded-2xl shadow hover:shadow-2xl overflow-hidden flex flex-col lift img-zoom">
    <a href="{{ url('/product/'.$product->id) }}" class="block">
        <div class="relative h-48 overflow-hidden bg-gradient-to-br from-stone-100 to-stone-200">
            @if ($product->primaryImageUrl())
                <img src="{{ $product->primaryImageUrl() }}" alt="{{ Translatable::pick($product->name) }}" loading="lazy"
                     class="absolute inset-0 w-full h-full object-cover">
                <div class="absolute inset-0 bg-gradient-to-t from-black/25 via-transparent to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-500"></div>
            @else
                <div class="absolute inset-0 grid place-items-center text-5xl">
                    {{ $product->material === 'silver' ? '💍' : ($product->material === 'accessory' ? '🎁' : '💛') }}
                </div>
            @endif
        </div>
    </a>
    <div class="p-4 flex flex-col flex-1">
        <span class="text-xs uppercase tracking-[0.18em] text-stone-400">{{ $product->material }}</span>
        <a href="{{ url('/product/'.$product->id) }}" class="font-medium hover:text-brand transition-colors line-clamp-2">{{ Translatable::pick($product->name) }}</a>
        @php $cardTotal = $product->displayTotal(); @endphp
        <div class="mt-auto pt-3 flex items-center justify-between">
            <span class="font-serif font-bold text-lg text-brand">{{ $cardTotal > 0 ? Money::display($cardTotal) : tr('On request') }}</span>
            <form method="POST" action="{{ route('cart.add', $product->id) }}">
                @csrf
                <button class="bg-brand text-white text-sm px-4 py-1.5 rounded-full hover:bg-brand-dark hover:shadow-md transition-all duration-300">{{ tr('Add') }}</button>
            </form>
        </div>
    </div>
</div>
