@extends('storefront.layouts.app')
@php use App\Support\Translatable; @endphp
@section('title', Translatable::pick($page->title).' — Lord ICL')

@section('content')
    <div class="max-w-3xl mx-auto px-4 py-12">
        <h1 class="text-4xl font-serif font-bold mb-8">{{ Translatable::pick($page->title) }}</h1>
        <div class="prose max-w-none text-stone-700 leading-relaxed">
            {!! Translatable::pick($page->body) !!}
        </div>
    </div>
@endsection
