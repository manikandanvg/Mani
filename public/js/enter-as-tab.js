/**
 * Enter-as-Tab: pressing Enter inside a multi-field form moves focus to the next
 * input instead of submitting (prevents accidental saves). Applied project-wide.
 *
 * Safety rules:
 *  - Single-field forms (e.g. a search box) still submit on Enter.
 *  - Textareas keep Enter (newline).
 *  - Searchable selects / comboboxes / autocompletes keep Enter (it picks an option).
 *  - Submit/button/checkbox/radio/file inputs are untouched.
 * Capture phase so it runs before framework (Livewire/Alpine) handlers.
 */
(function () {
    var SKIP_TYPES = ['submit', 'button', 'reset', 'checkbox', 'radio', 'file', 'image', 'search'];
    var FIELD_SELECTOR =
        'input:not([type=hidden]):not([type=submit]):not([type=button]):not([type=reset])' +
        ':not([type=checkbox]):not([type=radio]):not([type=file]):not([disabled]):not([readonly]),' +
        'select:not([disabled]), textarea:not([disabled])';

    function visible(el) {
        return el.offsetParent !== null || el.getClientRects().length > 0;
    }

    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter' || e.isComposing || e.shiftKey || e.ctrlKey || e.metaKey || e.altKey) {
            return;
        }

        var el = e.target;
        if (!el || el.tagName !== 'INPUT') {
            return; // textareas, selects, buttons behave normally
        }

        var type = (el.getAttribute('type') || 'text').toLowerCase();
        if (SKIP_TYPES.indexOf(type) !== -1) {
            return;
        }

        // leave searchable selects / autocompletes alone — Enter selects an option there
        if (
            el.getAttribute('role') === 'combobox' ||
            el.getAttribute('aria-autocomplete') ||
            el.getAttribute('aria-expanded') === 'true' ||
            (el.closest && el.closest('[role=combobox],[role=listbox],.choices,.fi-fo-select'))
        ) {
            return;
        }

        var form = el.closest('form');
        var fields = Array.prototype.slice
            .call((form || document).querySelectorAll(FIELD_SELECTOR))
            .filter(visible);

        // single-field forms (search boxes) should still submit on Enter
        if (form && fields.length <= 1) {
            return;
        }

        e.preventDefault();

        var idx = fields.indexOf(el);
        if (idx > -1 && idx + 1 < fields.length) {
            var next = fields[idx + 1];
            next.focus();
            if (typeof next.select === 'function') {
                try { next.select(); } catch (err) { /* noop */ }
            }
        } else {
            el.blur();
        }
    }, true);
})();
