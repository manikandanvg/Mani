@extends('storefront.layouts.app')
@section('title', 'Contact — Lord ICL')

@section('content')
    <div class="max-w-3xl mx-auto px-4 py-12">
        <h1 class="text-4xl font-serif font-bold mb-8">Contact us</h1>

        @if ($errors->any())
            <div class="bg-red-100 text-red-700 rounded-lg px-4 py-3 mb-6">
                <ul class="list-disc list-inside text-sm">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
            </div>
        @endif

        <form method="POST" action="{{ route('contact.submit') }}" class="space-y-4 bg-white rounded-2xl shadow p-6">
            @csrf
            <div class="grid sm:grid-cols-2 gap-4">
                <input name="name" value="{{ old('name') }}" placeholder="Your name" class="border rounded-lg px-4 py-2.5">
                <input name="email" value="{{ old('email') }}" placeholder="Email" class="border rounded-lg px-4 py-2.5">
            </div>
            <textarea name="message" rows="5" placeholder="How can we help?" class="w-full border rounded-lg px-4 py-2.5">{{ old('message') }}</textarea>
            <button class="bg-brand text-white px-8 py-3 rounded-full hover:bg-brand-dark transition">Send message</button>
        </form>
    </div>
@endsection
