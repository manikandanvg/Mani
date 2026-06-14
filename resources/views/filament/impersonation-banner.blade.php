@php
    $original = \App\Models\User::find(session('impersonator_id'));
@endphp

@if ($original)
    <div style="position:sticky;top:0;z-index:50;display:flex;align-items:center;justify-content:center;gap:.75rem;
                padding:.5rem 1rem;background:#e6ad46;color:#3a1e06;font-size:.875rem;font-weight:600;
                box-shadow:0 1px 3px rgba(0,0,0,.15);">
        <span>
            👁 Viewing as <strong>{{ auth()->user()?->name }}</strong>
            @if (auth()->user()?->branch?->name)
                — {{ auth()->user()->branch->name }}
            @endif
            <span style="font-weight:400;opacity:.8;">(admin: {{ $original->name }})</span>
        </span>
        <a href="{{ route('impersonate.leave') }}"
           style="background:#ab222f;color:#fff;padding:.25rem .75rem;border-radius:.375rem;text-decoration:none;font-weight:600;">
            ← Back to Admin
        </a>
    </div>
@endif
