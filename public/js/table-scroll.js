/**
 * Wide data tables (board 2026-11-15, "floating scroll"):
 *
 *  1. A horizontal scrollbar that FLOATS at the bottom of the window whenever a table is wider
 *     than the screen and its own scrollbar is still below the fold — no more scrolling to the
 *     end of the page just to reach the action buttons.
 *  2. Drag-to-scroll: hold the mouse on the table and drag left / right.
 *
 * Works on every Filament table (.fi-ta-content) and on the report tables (.icl-scroll-x);
 * survives Livewire re-renders because nothing is bound to a table element itself.
 */
(function () {
    var SCROLLERS = '.fi-ta-content, .icl-scroll-x';
    var bar = null, inner = null, target = null, syncing = false, queued = false;

    function build() {
        bar = document.createElement('div');
        bar.className = 'icl-float-scroll';
        inner = document.createElement('div');
        bar.appendChild(inner);
        document.body.appendChild(bar);
        bar.addEventListener('scroll', function () {
            if (syncing || !target) { return; }
            syncing = true;
            target.scrollLeft = bar.scrollLeft;
            syncing = false;
        });
    }

    /** The widest-overflowing table on screen whose bottom edge is below the window. */
    function pick() {
        var best = null, vh = window.innerHeight;
        document.querySelectorAll(SCROLLERS).forEach(function (el) {
            if (el.scrollWidth <= el.clientWidth + 2) { return; }
            if (el.closest('.fi-modal')) { return; }
            var r = el.getBoundingClientRect();
            if (r.top < vh - 80 && r.bottom > vh) { best = best || el; }
        });
        return best;
    }

    function update() {
        queued = false;
        if (!bar) { build(); }
        var el = pick();
        if (!el) {
            bar.style.display = 'none';
            target = null;
            return;
        }
        var r = el.getBoundingClientRect();
        target = el;
        bar.style.display = 'block';
        bar.style.left = r.left + 'px';
        bar.style.width = r.width + 'px';
        inner.style.width = el.scrollWidth + 'px';
        if (Math.abs(bar.scrollLeft - el.scrollLeft) > 1) {
            syncing = true;
            bar.scrollLeft = el.scrollLeft;
            syncing = false;
        }
    }

    function schedule() {
        if (queued) { return; }
        queued = true;
        window.requestAnimationFrame(update);
    }

    window.addEventListener('scroll', schedule, true);   // capture: also the table's own scroll
    window.addEventListener('resize', schedule);
    document.addEventListener('DOMContentLoaded', function () {
        schedule();
        new MutationObserver(schedule).observe(document.body, { childList: true, subtree: true });
    });
    document.addEventListener('livewire:navigated', schedule);

    // ── drag to scroll ───────────────────────────────────────────────────────────────
    var drag = null;
    var INTERACTIVE = 'a, button, input, select, textarea, label, [role=button], [contenteditable], .fi-dropdown, .choices';

    document.addEventListener('mousedown', function (e) {
        if (e.button !== 0 || !e.target.closest) { return; }
        var el = e.target.closest(SCROLLERS);
        if (!el || el.scrollWidth <= el.clientWidth + 2 || e.target.closest(INTERACTIVE)) { return; }
        drag = { el: el, x: e.pageX, left: el.scrollLeft, moved: false };
    });

    document.addEventListener('mousemove', function (e) {
        if (!drag) { return; }
        var dx = e.pageX - drag.x;
        if (!drag.moved && Math.abs(dx) < 6) { return; }
        if (!drag.moved) {
            drag.moved = true;
            drag.el.classList.add('icl-dragging');
            var sel = window.getSelection && window.getSelection();
            if (sel && sel.removeAllRanges) { sel.removeAllRanges(); }
        }
        e.preventDefault();
        drag.el.scrollLeft = drag.left - dx;
    });

    function end() {
        if (!drag) { return; }
        drag.el.classList.remove('icl-dragging');
        var moved = drag.moved;
        drag = null;
        if (moved) {
            // swallow the click that ends a drag, so nothing under the mouse fires
            var stop = function (ev) { ev.stopPropagation(); ev.preventDefault(); };
            document.addEventListener('click', stop, true);
            setTimeout(function () { document.removeEventListener('click', stop, true); }, 0);
        }
    }
    document.addEventListener('mouseup', end);
    document.addEventListener('mouseleave', end);
})();
