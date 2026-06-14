@extends('storefront.layouts.app')
@php use App\Support\Translatable; @endphp
@section('title', Translatable::pick($post->title).' — Lord ICL')

@section('content')
    <article class="max-w-3xl mx-auto px-4 py-12">
        <a href="{{ url('/'.$type) }}" class="text-sm text-stone-500 hover:text-brand">← All {{ $type }}</a>
        <p class="mt-4 text-xs uppercase text-brand">{{ $type }} · {{ optional($post->published_at)->format('d M Y') }}</p>
        <h1 class="mt-2 text-4xl font-serif font-bold">{{ Translatable::pick($post->title) }}</h1>
        @if ($post->author_name)<p class="mt-2 text-stone-500">By {{ $post->author_name }}</p>@endif
        <div class="h-64 bg-stone-200 rounded-2xl my-8"></div>
        <div class="prose max-w-none text-stone-700 leading-relaxed">
            {!! Translatable::pick($post->body) !!}
        </div>
    </article>
@endsection
