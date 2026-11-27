{{-- Admin To-do (board 2026-11-27): floating button on the dashboard; opens the list of what waits
     for Head Office. The button shows the total, each line its own count. --}}
@php
    $items = \App\Support\AdminTodo::items();
    $total = array_sum(array_column($items, 'count'));
@endphp

<div x-data="{ open: false }" x-on:keydown.escape.window="open = false" class="icl-todo">
    <div x-show="open" x-cloak x-transition.opacity x-on:click.outside="open = false" class="icl-todo-panel">
        <div class="icl-todo-head">
            <span>{{ __('Admin To-do') }}</span>
            <span class="icl-todo-pill {{ $total > 0 ? 'is-on' : '' }}">{{ number_format($total) }} {{ __('pending') }}</span>
        </div>
        <ul>
            @foreach ($items as $i => $item)
                <li>
                    <a href="{{ $item['url'] }}" class="icl-todo-row {{ $item['count'] > 0 ? '' : 'is-clear' }}">
                        <span class="icl-todo-no">{{ $i + 1 }}</span>
                        <span class="icl-todo-text">
                            <span class="icl-todo-label">{{ __($item['label']) }}</span>
                            <span class="icl-todo-hint">{{ __($item['hint']) }}</span>
                        </span>
                        <span class="icl-todo-pill {{ $item['count'] > 0 ? 'is-on' : '' }}">{{ number_format($item['count']) }}</span>
                    </a>
                </li>
            @endforeach
        </ul>
    </div>

    <button type="button" x-on:click="open = ! open" class="icl-todo-fab" title="{{ __('Admin To-do') }}" aria-label="{{ __('Admin To-do') }}">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" width="26" height="26">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 0 0 2.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 0 0-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 0 0 .75-.75 2.25 2.25 0 0 0-.1-.664m-5.8 0A2.251 2.251 0 0 1 13.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25ZM6.75 12h.008v.008H6.75V12Zm0 3h.008v.008H6.75V15Zm0 3h.008v.008H6.75V18Z" />
        </svg>
        @if ($total > 0)
            <span class="icl-todo-badge">{{ $total > 999 ? '999+' : $total }}</span>
        @endif
    </button>
</div>

<style>
    .icl-todo { position: fixed; right: 1.5rem; bottom: 4.5rem; z-index: 40; }
    .icl-todo [x-cloak] { display: none !important; }
    .icl-todo-fab { position: relative; display: flex; align-items: center; justify-content: center; width: 3.5rem; height: 3.5rem;
        border-radius: 9999px; background: #ab222f; color: #fff; box-shadow: 0 10px 25px rgba(171, 34, 47, .4); transition: transform .15s ease; }
    .icl-todo-fab:hover { transform: translateY(-2px) scale(1.04); }
    .icl-todo-badge { position: absolute; top: -.35rem; right: -.35rem; min-width: 1.5rem; height: 1.5rem; padding: 0 .35rem; border-radius: 9999px;
        background: #e6ad46; color: #3a1e06; font-size: .72rem; font-weight: 800; line-height: 1.5rem; text-align: center; border: 2px solid #fff; }
    .icl-todo-panel { position: absolute; right: 0; bottom: 4.25rem; width: min(24rem, calc(100vw - 2rem)); max-height: 70vh; overflow: auto;
        background: #fff; color: #1c1917; border: 1px solid rgba(0, 0, 0, .08); border-radius: .9rem; box-shadow: 0 20px 45px rgba(0, 0, 0, .22); }
    .dark .icl-todo-panel { background: #1c1917; color: #f5f5f4; border-color: rgba(255, 255, 255, .12); }
    .icl-todo-head { display: flex; align-items: center; justify-content: space-between; padding: .8rem 1rem; font-weight: 700;
        border-bottom: 1px solid rgba(128, 128, 128, .2); position: sticky; top: 0; background: inherit; }
    .icl-todo-row { display: flex; align-items: center; gap: .75rem; padding: .65rem 1rem; border-bottom: 1px solid rgba(128, 128, 128, .12); }
    .icl-todo-row:hover { background: rgba(171, 34, 47, .07); }
    .icl-todo-row.is-clear { opacity: .6; }
    .icl-todo-no { width: 1.5rem; height: 1.5rem; flex: none; border-radius: 9999px; background: rgba(128, 128, 128, .15); font-size: .72rem;
        font-weight: 700; line-height: 1.5rem; text-align: center; }
    .icl-todo-text { flex: 1; min-width: 0; display: flex; flex-direction: column; }
    .icl-todo-label { font-size: .9rem; font-weight: 600; }
    .icl-todo-hint { font-size: .72rem; opacity: .65; }
    .icl-todo-pill { flex: none; min-width: 1.9rem; padding: .1rem .5rem; border-radius: 9999px; background: rgba(128, 128, 128, .18);
        font-size: .78rem; font-weight: 700; text-align: center; }
    .icl-todo-pill.is-on { background: #ab222f; color: #fff; }
</style>
