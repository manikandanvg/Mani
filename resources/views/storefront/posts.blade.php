@extends('storefront.layouts.app')
@php use App\Support\Translatable; @endphp
@section('title', ucfirst($type).' — LORD JEWELLER')

@section('content')
    <div class="max-w-7xl mx-auto px-4 py-10">
        <h1 class="text-3xl font-serif font-semibold mb-8 capitalize">{{ $type }}</h1>
        @if ($posts->isEmpty())
            <p class="text-stone-500">Nothing published yet.</p>
        @else
            <div class="grid md:grid-cols-3 gap-6">
                @foreach ($posts as $post)
                    <a href="{{ url('/'.$type.'/'.$post->slug) }}"
                       class="block bg-white rounded-2xl shadow hover:shadow-xl overflow-hidden lift img-zoom reveal"
                       style="transition-delay: {{ ($loop->index % 3) * 100 }}ms">
                        <div class="h-44 overflow-hidden bg-stone-200">
                            @if ($post->imageUrl())
                                <img src="{{ $post->imageUrl() }}" alt="{{ Translatable::pick($post->title) }}" loading="lazy"
                                     class="w-full h-full object-cover">
                            @endif
                        </div>
                        <div class="p-5">
                            <p class="text-xs text-stone-400">{{ optional($post->published_at)->format('d M Y') }}</p>
                            <h3 class="mt-1 font-serif font-bold text-lg leading-snug">{{ Translatable::pick($post->title) }}</h3>
                            <p class="mt-2 text-sm text-stone-500 font-light">{{ Translatable::pick($post->excerpt) }}</p>
                        </div>
                    </a>
                @endforeach
            </div>
            <div class="mt-10">{{ $posts->links() }}</div>
        @endif
    </div>
@endsection
