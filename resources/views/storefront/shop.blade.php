@extends('storefront.layouts.app')
@php use App\Support\Translatable; @endphp
@section('title', 'Shop — LORD JEWELLER')

@section('content')
    <div class="max-w-7xl mx-auto px-4 py-10">
        <h1 class="text-3xl font-serif font-bold mb-6">{{ tr('Shop') }}</h1>

        <div class="flex flex-wrap gap-2 mb-8">
            <a href="{{ url('/shop') }}" class="px-4 py-1.5 rounded-full text-sm {{ ! $activeCategory ? 'bg-brand text-white' : 'bg-white border' }}">{{ tr('All') }}</a>
            @foreach ($categories as $cat)
                <a href="{{ url('/shop?category='.$cat->id) }}"
                   class="px-4 py-1.5 rounded-full text-sm {{ (int) $activeCategory === $cat->id ? 'bg-brand text-white' : 'bg-white border' }}">
                    {{ Translatable::pick($cat->name) }}
                </a>
            @endforeach
        </div>

        @if ($products->isEmpty())
            <p class="text-stone-500">{{ tr('No products found.') }}</p>
        @else
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-6">
                @foreach ($products as $product)
                    @include('storefront.partials.product-card', ['product' => $product])
                @endforeach
            </div>
            <div class="mt-10">{{ $products->links() }}</div>
        @endif
    </div>
@endsection
