{{-- Sidebar navigation search (user 2026-08-29). Pure client-side: filters the rendered
     menu as you type, opens groups that hold a match, hides the rest. Esc clears. --}}
<div class="icl-nav-search" style="padding:.25rem .5rem .5rem">
    <label style="position:relative;display:block">
        <span style="position:absolute;left:.6rem;top:50%;transform:translateY(-50%);color:#9ca3af;pointer-events:none">
            <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><path d="m20 20-3.5-3.5"></path></svg>
        </span>
        <input
            type="search"
            id="icl-nav-search"
            class="fi-input"
            placeholder="{{ __('Search menu…') }}"
            autocomplete="off"
            aria-label="{{ __('Search menu') }}"
            style="width:100%;padding:.45rem .6rem .45rem 2rem;border:1px solid #e5e7eb;border-radius:.6rem;font-size:.85rem;background:transparent;color:inherit"
        >
    </label>
</div>
<script>
(function () {
    function ready(fn) { document.readyState !== 'loading' ? fn() : document.addEventListener('DOMContentLoaded', fn); }
    ready(function () {
        var input = document.getElementById('icl-nav-search');
        if (!input || input.dataset.bound) return;
        input.dataset.bound = '1';
        var nav = input.closest('nav') || input.closest('.fi-sidebar-nav') || document;

        function apply() {
            var q = input.value.trim().toLowerCase();
            var groups = nav.querySelectorAll('.fi-sidebar-group');
            var items = nav.querySelectorAll('.fi-sidebar-item');
            if (!q) {
                items.forEach(function (i) { i.style.display = ''; });
                groups.forEach(function (g) { g.style.display = ''; });
                return;
            }
            items.forEach(function (i) {
                var hit = i.textContent.toLowerCase().indexOf(q) !== -1;
                i.style.display = hit ? '' : 'none';
            });
            groups.forEach(function (g) {
                var any = Array.prototype.some.call(g.querySelectorAll('.fi-sidebar-item'), function (i) { return i.style.display !== 'none'; });
                var label = (g.querySelector('.fi-sidebar-group-label') || {}).textContent || '';
                var groupHit = label.toLowerCase().indexOf(q) !== -1;
                if (groupHit) { g.querySelectorAll('.fi-sidebar-item').forEach(function (i) { i.style.display = ''; }); }
                g.style.display = (any || groupHit) ? '' : 'none';
                if (any || groupHit) {
                    // Open a collapsed group so the match is visible (Alpine x-data on the group).
                    var list = g.querySelector('.fi-sidebar-group-items');
                    if (list && list.style.display === 'none') list.style.display = '';
                    var btn = g.querySelector('.fi-sidebar-group-collapse-button');
                    if (btn && g.querySelector('.fi-sidebar-group-items') && getComputedStyle(g.querySelector('.fi-sidebar-group-items')).display === 'none') btn.click();
                }
            });
        }
        input.addEventListener('input', apply);
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') { input.value = ''; apply(); input.blur(); }
            if (e.key === 'Enter') {
                var first = nav.querySelector('.fi-sidebar-item:not([style*="display: none"]) a');
                if (first) { e.preventDefault(); first.click(); }
            }
        });
        // "/" focuses the menu search from anywhere (not while typing in a field).
        document.addEventListener('keydown', function (e) {
            if (e.key === '/' && !/input|textarea|select/i.test((e.target && e.target.tagName) || '') && !e.target.isContentEditable) {
                e.preventDefault(); input.focus();
            }
        });
    });
})();
</script>
