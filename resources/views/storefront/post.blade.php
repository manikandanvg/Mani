@extends('storefront.layouts.app')
@php use App\Support\Translatable; @endphp
@section('title', Translatable::pick($post->title).' — LORD JEWELLER')

@section('content')
    <article class="max-w-3xl mx-auto px-4 py-12">
        <a href="{{ url('/'.$type) }}" class="text-sm text-stone-500 hover:text-brand transition-colors">← All {{ $type }}</a>
        <p class="mt-4 text-xs uppercase tracking-[0.18em] text-brand">{{ $type }} · {{ optional($post->published_at)->format('d M Y') }}</p>
        <h1 class="mt-2 text-4xl font-serif font-semibold leading-tight">{{ Translatable::pick($post->title) }}</h1>
        @if ($post->author_name)<p class="mt-2 text-stone-500">By {{ $post->author_name }}</p>@endif
        @if ($post->imageUrl())
            <div class="rounded-2xl overflow-hidden shadow-lg my-8 max-h-[420px]">
                <img src="{{ $post->imageUrl() }}" alt="{{ Translatable::pick($post->title) }}" class="w-full h-full object-cover">
            </div>
        @else
            <div class="h-px gold-rule my-8"></div>
        @endif
        <div class="prose max-w-none text-stone-700 leading-relaxed">
            {!! Translatable::pick($post->body) !!}
        </div>
    </article>
@endsection
