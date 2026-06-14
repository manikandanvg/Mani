@extends('storefront.layouts.app')
@php use App\Support\Translatable; @endphp
@section('title', ucfirst($type).' — Lord ICL')

@section('content')
    <div class="max-w-7xl mx-auto px-4 py-10">
        <h1 class="text-3xl font-serif font-bold mb-8 capitalize">{{ $type }}</h1>
        @if ($posts->isEmpty())
            <p class="text-stone-500">Nothing published yet.</p>
        @else
            <div class="grid md:grid-cols-3 gap-6">
                @foreach ($posts as $post)
                    <a href="{{ url('/'.$type.'/'.$post->slug) }}" class="block bg-white rounded-2xl shadow hover:shadow-lg transition overflow-hidden">
                        <div class="h-44 bg-stone-200"></div>
                        <div class="p-5">
                            <p class="text-xs text-stone-400">{{ optional($post->published_at)->format('d M Y') }}</p>
                            <h3 class="mt-1 font-serif font-bold">{{ Translatable::pick($post->title) }}</h3>
                            <p class="mt-2 text-sm text-stone-500">{{ Translatable::pick($post->excerpt) }}</p>
                        </div>
                    </a>
                @endforeach
            </div>
            <div class="mt-10">{{ $posts->links() }}</div>
        @endif
    </div>
@endsection
