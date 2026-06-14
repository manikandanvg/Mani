@extends('storefront.layouts.app')
@php use App\Support\Translatable; @endphp
@section('title', 'FAQ — Lord ICL')

@section('content')
    <div class="max-w-3xl mx-auto px-4 py-12">
        <h1 class="text-4xl font-serif font-bold mb-8">Frequently asked questions</h1>
        @forelse ($faqs as $faq)
            <div class="border-b py-5" x-data="{ o: false }">
                <button class="w-full flex justify-between items-center text-left font-medium" @click="o=!o">
                    <span>{{ Translatable::pick($faq->question) }}</span>
                    <span x-text="o ? '−' : '+'" class="text-brand text-xl"></span>
                </button>
                <p x-show="o" x-cloak class="mt-3 text-stone-600">{{ Translatable::pick($faq->answer) }}</p>
            </div>
        @empty
            <p class="text-stone-500">No FAQs yet.</p>
        @endforelse
    </div>
@endsection
