<x-filament-panels::page>
<div id="icl-geneao"
     x-data="{
        z: 1, x: 0, y: 0, dragging: false, sx: 0, sy: 0, q: '',
        selected: {}, modal: false, notFound: false, matches: [], busy: false,
        zoomIn() { this.z = Math.min(2.5, this.z + 0.1) },
        zoomOut() { this.z = Math.max(0.25, this.z - 0.1) },
        reset() { this.z = 1; this.x = 0; this.y = 0 },
        wheel(e) { this.z = Math.min(2.5, Math.max(0.25, this.z + (e.deltaY < 0 ? 0.1 : -0.1))) },
        start(e) { this.dragging = true; this.sx = e.clientX - this.x; this.sy = e.clientY - this.y },
        move(e) { if (this.dragging) { this.x = e.clientX - this.sx; this.y = e.clientY - this.sy } },
        end() { this.dragging = false },
        centerOn(el) {
            if (! el) return;
            const vp = this.$refs.viewport.getBoundingClientRect();
            const nr = el.getBoundingClientRect();
            this.x += (vp.left + vp.width / 2) - (nr.left + nr.width / 2);
            this.y += (vp.top + vp.height / 2) - (nr.top + nr.height / 2);
        },
        pick(el) {
            this.selected = {
                name: el.dataset.name, code: el.dataset.code, position: el.dataset.position,
                count: el.dataset.count, active: el.dataset.active === '1', url: el.dataset.url,
                verified: el.dataset.verified === '1',
                initial: (el.dataset.name || '?').charAt(0).toUpperCase()
            };
            this.modal = true;
            this.$nextTick(() => this.centerOn(el));
        },
        filter() {
            const t = this.q.trim().toLowerCase();
            let first = null; this.matches = [];
            this.$root.querySelectorAll('.icl-card').forEach(c => {
                const hit = t && c.dataset.search.includes(t);
                c.classList.toggle('icl-hit', !!hit);
                c.classList.toggle('icl-dim', !!t && !hit);
                if (hit) {
                    if (! first) first = c;
                    if (this.matches.length < 200) this.matches.push({
                        name: c.dataset.name, code: c.dataset.code,
                        position: c.dataset.position, active: c.dataset.active === '1',
                        initial: (c.dataset.name || '?').charAt(0).toUpperCase()
                    });
                }
            });
            this.notFound = !!t && this.matches.length === 0;
            if (first) this.$nextTick(() => this.centerOn(first));
        },
        goTo(code) {
            const el = this.$root.querySelector('.icl-card[data-code=\'' + (window.CSS ? CSS.escape(code) : code) + '\']');
            if (! el) return;
            this.$root.querySelectorAll('.icl-card.icl-current').forEach(c => c.classList.remove('icl-current'));
            el.classList.add('icl-current');
            this.centerOn(el);
        },
        printChart() {
            const p = { z: this.z, x: this.x, y: this.y };
            this.$dispatch('icl-expand'); this.z = 1; this.x = 0; this.y = 0;
            this.$nextTick(() => setTimeout(() => { window.print(); this.z = p.z; this.x = p.x; this.y = p.y; }, 250));
        },
        loadH2C() {
            return new Promise((res, rej) => {
                if (window.html2canvas) return res();
                const s = document.createElement('script');
                s.src = 'https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js';
                s.onload = res; s.onerror = rej; document.head.appendChild(s);
            });
        },
        async exportPng(full = false) {
            this.busy = true;
            const stage = this.$root.querySelector('.icl-stage');
            const vp = this.$refs.viewport;
            const tree = this.$root.querySelector('.icl-tree');
            const saved = { tf: stage.style.transform, tr: stage.style.transition, ps: stage.style.position, lf: stage.style.left, vo: vp.style.overflow, vh: vp.style.height };
            try {
                await this.loadH2C();
                // 'view' = capture the tree as currently shown (respect collapse state).
                // 'full' = expand everything first. Either way, flatten zoom/pan and un-clip.
                if (full) this.$dispatch('icl-expand');
                stage.style.transition = 'none';
                stage.style.transform = 'none';
                stage.style.position = 'static';
                stage.style.left = '0';
                vp.style.overflow = 'visible';
                vp.style.height = 'auto';
                await new Promise(r => setTimeout(r, full ? 400 : 120));
                const isDark = document.documentElement.classList.contains('dark');
                const w = Math.ceil(tree.scrollWidth), h = Math.ceil(tree.scrollHeight);
                // keep the bitmap under browser canvas limits (~16k px / area) so big trees never clip
                const scale = Math.min(2, 12000 / Math.max(w, h, 1));
                const canvas = await html2canvas(tree, {
                    backgroundColor: isDark ? '#0f172a' : '#ffffff',
                    scale: scale, logging: false, useCORS: true,
                    width: w, height: h, windowWidth: w + 100, windowHeight: h + 100,
                });
                const link = document.createElement('a');
                link.download = 'genealogy-' + new Date().toISOString().slice(0, 10) + '.png';
                link.href = canvas.toDataURL('image/png');
                link.click();
            } catch (e) {
                console.error('PNG export failed', e);
                window.alert('PNG export failed (' + (e && e.message ? e.message : e) + '). Check your internet connection — the exporter loads once from a CDN. You can use Print instead.');
            } finally {
                // restore the interactive view
                stage.style.transform = saved.tf; stage.style.transition = saved.tr;
                stage.style.position = saved.ps; stage.style.left = saved.lf;
                vp.style.overflow = saved.vo; vp.style.height = saved.vh;
                this.busy = false;
            }
        }
     }">

    <div class="icl-toolbar">
        <div class="icl-searchwrap">
            <input type="text" class="icl-search" placeholder="Search name or code…"
                   x-model="q" @input.debounce.200ms="$dispatch('icl-expand'); filter()">
            <span class="icl-notfound" x-show="notFound" x-cloak x-text="`No distributor matches “${q}”`"></span>

            {{-- results list when searching --}}
            <div class="icl-results" x-show="q && matches.length" x-cloak x-transition.opacity>
                <div class="icl-results-head" x-text="matches.length + (matches.length === 1 ? ' match' : ' matches')"></div>
                <div class="icl-results-list">
                    <template x-for="m in matches" :key="m.code">
                        <button type="button" class="icl-result" @click="goTo(m.code)" :class="{ 'icl-result-off': ! m.active }">
                            <span class="icl-result-ava" x-text="m.initial"></span>
                            <span class="icl-result-meta">
                                <b x-text="m.name"></b>
                                <small><span x-text="m.code"></span><template x-if="m.position"><em x-text="' · ' + m.position"></em></template></small>
                            </span>
                        </button>
                    </template>
                </div>
            </div>
        </div>
        <button type="button" class="icl-btn" @click="$dispatch('icl-expand')">Expand all</button>
        <button type="button" class="icl-btn" @click="$dispatch('icl-collapse')">Collapse all</button>
        <span class="icl-sep"></span>
        <button type="button" class="icl-btn" @click="zoomOut()">&minus;</button>
        <button type="button" class="icl-btn" @click="reset()">Reset</button>
        <button type="button" class="icl-btn" @click="zoomIn()">+</button>
        <span class="icl-sep"></span>
        <button type="button" class="icl-btn icl-btn-ghost" @click="exportPng(false)" :disabled="busy" title="Export the current view as PNG">
            <span x-show="! busy">⤓ PNG</span><span x-show="busy" x-cloak>…</span>
        </button>
        <button type="button" class="icl-btn icl-btn-ghost" @click="exportPng(true)" :disabled="busy" title="Expand everything and export the whole tree">⤓ Full</button>
        <button type="button" class="icl-btn icl-btn-ghost" @click="printChart()">⎙ Print</button>
        <span class="icl-hint">click a node for details · drag to pan · scroll to zoom</span>
    </div>

    <div class="icl-viewport" x-ref="viewport" :class="{ 'icl-grab': dragging }"
         @pointerdown="start" @pointermove="move" @pointerup="end" @pointerleave="end"
         @wheel.prevent="wheel($event)">
        <div class="icl-stage" :class="{ 'icl-dragging': dragging }"
             :style="`transform: translate(${x}px, ${y}px) scale(${z});`">
            <div class="icl-tree">
                <ul>
                    @foreach ($this->getTree() as $node)
                        @include('filament.pages.partials.genealogy-node', ['node' => $node, 'depth' => 0])
                    @endforeach
                </ul>
            </div>
        </div>

        {{-- details popup --}}
        <div class="icl-modal-backdrop" x-show="modal" x-cloak x-transition.opacity
             @click.self="modal = false" @keydown.escape.window="modal = false">
            <div class="icl-modal" x-show="modal"
                 x-transition:enter="icl-pop-enter" x-transition:enter-start="icl-pop-start">
                <button type="button" class="icl-modal-x" @click="modal = false">&times;</button>
                <div class="icl-modal-head">
                    <div class="icl-ava icl-ava-lg" x-text="selected.initial"></div>
                    <div>
                        <div class="icl-modal-name" x-text="selected.name"></div>
                        <div class="icl-modal-code" x-text="selected.code"></div>
                    </div>
                </div>
                <div class="icl-modal-rows">
                    <div><span>Position</span><b x-text="selected.position || '—'"></b></div>
                    <div><span>Status</span>
                        <b :class="selected.active ? 'icl-ok' : 'icl-off'" x-text="selected.active ? 'Active' : 'Inactive'"></b>
                    </div>
                    <div><span>Team size</span><b x-text="selected.count"></b></div>
                    <div><span>KYC</span>
                        <b :class="selected.verified ? 'icl-ok' : 'icl-off'" x-text="selected.verified ? '✓ Verified' : 'Pending'"></b>
                    </div>
                </div>
                <a class="icl-modal-open" :href="selected.url" x-show="selected.url" target="_blank">Open distributor record →</a>
            </div>
        </div>
    </div>
</div>

<style>
    [x-cloak] { display: none !important; }
    #icl-geneao { --line: #e6ad46; }
    .icl-toolbar { display: flex; gap: .5rem; align-items: center; flex-wrap: wrap; margin-bottom: .75rem; }
    .icl-searchwrap { position: relative; display: flex; flex-direction: column; }
    .icl-search { padding: .45rem .75rem; border: 1px solid #d1d5db; border-radius: .5rem; font-size: .85rem; min-width: 240px; }
    .dark .icl-search { background: #1f2937; border-color: #374151; color: #f3f4f6; }
    .icl-notfound { position: absolute; top: 110%; left: 0; font-size: .72rem; color: #ab222f; font-weight: 600; }

    .icl-results { position: absolute; top: 110%; left: 0; z-index: 25; width: 280px; background: #fff; border: 1px solid #e5e7eb;
        border-radius: .6rem; box-shadow: 0 12px 30px rgba(0,0,0,.18); overflow: hidden; }
    .dark .icl-results { background: #1f2937; border-color: #374151; }
    .icl-results-head { font-size: .7rem; font-weight: 700; color: #92400e; background: #fdeccf; padding: .35rem .7rem; }
    .icl-results-list { max-height: 240px; overflow-y: auto; }
    .icl-result { display: flex; align-items: center; gap: .55rem; width: 100%; text-align: left;
        padding: .45rem .7rem; border: none; border-top: 1px solid #f1f1f1; background: none; cursor: pointer; font-size: .8rem; }
    .dark .icl-result { border-color: #374151; color: #f3f4f6; }
    .icl-result:hover { background: #fdeccf; } .dark .icl-result:hover { background: #374151; }
    .icl-result-ava { flex: 0 0 auto; width: 26px; height: 26px; border-radius: 50%; display: flex; align-items: center; justify-content: center;
        color: #fff; font-weight: 700; font-size: .75rem; background: linear-gradient(135deg, #ab222f, #e6ad46); }
    .icl-result-meta { display: flex; flex-direction: column; line-height: 1.15; overflow: hidden; }
    .icl-result-meta b { font-weight: 600; white-space: nowrap; }
    .icl-result-meta small { font-size: .68rem; color: #9ca3af; }
    .icl-result-off { opacity: .55; }

    .icl-btn { padding: .45rem .8rem; border: 1px solid #ab222f; background: #ab222f; color: #fff; border-radius: .5rem; font-size: .8rem; font-weight: 600; cursor: pointer; line-height: 1; }
    .icl-btn:hover { background: #8c1c27; }
    .icl-btn:disabled { opacity: .6; cursor: default; }
    .icl-btn-ghost { background: #fff; color: #ab222f; } .dark .icl-btn-ghost { background: transparent; }
    .icl-btn-ghost:hover { background: #fdeccf; }
    .icl-sep { width: 1px; height: 22px; background: #e5e7eb; }
    .icl-hint { font-size: .72rem; color: #9ca3af; }

    .icl-viewport { position: relative; overflow: hidden; width: 100%; height: calc(100vh - 12rem); min-height: 680px;
        background: radial-gradient(circle at 20% 0%, #fafafa, #ffffff); border: 1px solid #e5e7eb; border-radius: .75rem; cursor: grab; touch-action: none; }
    .icl-viewport.icl-grab { cursor: grabbing; }
    .dark .icl-viewport { background: #0f172a; border-color: #374151; }
    .icl-stage { position: absolute; top: 36px; left: 50%; transform-origin: top center; will-change: transform;
        transition: transform .35s cubic-bezier(.22,.61,.36,1); }
    .icl-stage.icl-dragging { transition: none; }   /* instant while dragging */

    /* connectors (pure CSS) */
    .icl-tree ul { display: flex; justify-content: center; padding: 26px 0 0; margin: 0; list-style: none; position: relative; }
    .icl-tree li { display: flex; flex-direction: column; align-items: center; list-style: none; position: relative; padding: 26px 14px 0; }
    .icl-tree li::before, .icl-tree li::after { content: ''; position: absolute; top: 0; right: 50%; width: 50%; height: 26px; border-top: 2px solid var(--line); }
    .icl-tree li::after { right: auto; left: 50%; border-left: 2px solid var(--line); }
    .icl-tree li:only-child::before, .icl-tree li:only-child::after { display: none; }
    .icl-tree li:only-child { padding-top: 0; }
    .icl-tree li:first-child::before, .icl-tree li:last-child::after { border: 0 none; }
    .icl-tree li:last-child::before { border-right: 2px solid var(--line); border-radius: 0 6px 0 0; }
    .icl-tree li:first-child::after { border-radius: 6px 0 0 0; }
    .icl-tree ul ul::before { content: ''; position: absolute; top: 0; left: 50%; width: 0; height: 26px; border-left: 2px solid var(--line); }

    /* node card */
    .icl-card { display: flex; align-items: center; gap: .6rem; background: #fff; border: 1px solid #e5e7eb; border-radius: .85rem;
        padding: .6rem .8rem; box-shadow: 0 1px 3px rgba(0,0,0,.08); min-width: 200px; position: relative; cursor: pointer;
        transition: box-shadow .18s, border-color .18s, opacity .25s, transform .12s; }
    .icl-card:active { transform: scale(.97); }
    .dark .icl-card { background: #1f2937; border-color: #374151; }
    .icl-card:hover { box-shadow: 0 6px 18px rgba(171,34,47,.18); border-color: #ab222f; transform: translateY(-1px); }
    .icl-ava { width: 42px; height: 42px; border-radius: 50%; flex: 0 0 auto; display: flex; align-items: center; justify-content: center;
        color: #fff; font-weight: 700; font-size: 1.05rem; background: linear-gradient(135deg, #ab222f, #e6ad46); }
    .icl-meta { text-align: left; }
    .icl-name { font-weight: 600; font-size: .9rem; line-height: 1.15; color: #111827; }
    .dark .icl-name { color: #f3f4f6; }
    .icl-code { font-size: .72rem; color: #6b7280; }
    .icl-pos { display: inline-block; margin-top: 3px; font-size: .62rem; font-weight: 600; color: #92400e;
        background: #fdeccf; border: 1px solid #e6ad46; padding: 1px 7px; border-radius: 999px; }
    .icl-toggle { position: absolute; left: 50%; bottom: -13px; transform: translateX(-50%); z-index: 2; display: flex; align-items: center; gap: 4px;
        background: #ab222f; color: #fff; border: 2px solid #fff; border-radius: 999px; font-size: .72rem; line-height: 1; padding: 2px 9px; cursor: pointer; box-shadow: 0 1px 3px rgba(0,0,0,.25); transition: background .15s, transform .12s; }
    .icl-toggle:hover { background: #8c1c27; transform: translateX(-50%) scale(1.08); }
    .dark .icl-toggle { border-color: #1f2937; }
    .icl-count { font-weight: 700; }

    .icl-inactive { opacity: .55; }
    .icl-inactive .icl-ava { background: #9ca3af; }
    /* KYC verified tick (board 2026-08-12 items 1/2) */
    .icl-ava { position: relative; }
    .icl-verified { position: absolute; right: -4px; bottom: -3px; width: 16px; height: 16px; border-radius: 50%;
        background: #059669; color: #fff; font-size: .62rem; font-weight: 800; display: flex; align-items: center;
        justify-content: center; border: 2px solid #fff; line-height: 1; }
    .dark .icl-verified { border-color: #1f2937; }
    .icl-card.icl-dim { opacity: .2; filter: grayscale(1); }
    .icl-card.icl-hit { outline: 3px solid #e6ad46; outline-offset: 2px; }
    .icl-card.icl-current { outline: 3px solid #ab222f; outline-offset: 2px; box-shadow: 0 0 0 6px rgba(171,34,47,.15); }

    /* modal */
    .icl-modal-backdrop { position: absolute; inset: 0; background: rgba(17,24,39,.45); display: flex; align-items: center; justify-content: center; z-index: 30; }
    .icl-modal { position: relative; width: 320px; max-width: 90%; background: #fff; border-radius: 1rem; padding: 1.25rem; box-shadow: 0 20px 50px rgba(0,0,0,.3); }
    .dark .icl-modal { background: #1f2937; color: #f3f4f6; }
    .icl-pop-enter { transition: transform .2s cubic-bezier(.22,.61,.36,1), opacity .2s; }
    .icl-pop-start { transform: scale(.9) translateY(8px); opacity: 0; }
    .icl-modal-x { position: absolute; top: .5rem; right: .7rem; border: none; background: none; font-size: 1.4rem; line-height: 1; cursor: pointer; color: #9ca3af; }
    .icl-modal-head { display: flex; align-items: center; gap: .75rem; margin-bottom: 1rem; }
    .icl-ava-lg { width: 56px; height: 56px; font-size: 1.4rem; }
    .icl-modal-name { font-weight: 700; font-size: 1.05rem; }
    .icl-modal-code { font-size: .8rem; color: #6b7280; }
    .icl-modal-rows > div { display: flex; justify-content: space-between; padding: .45rem 0; border-top: 1px solid #f0f0f0; font-size: .85rem; }
    .dark .icl-modal-rows > div { border-color: #374151; }
    .icl-modal-rows span { color: #6b7280; }
    .icl-ok { color: #059669; } .icl-off { color: #ab222f; }
    .icl-modal-open { display: block; margin-top: 1rem; text-align: center; background: #ab222f; color: #fff; font-weight: 600;
        font-size: .82rem; padding: .55rem; border-radius: .6rem; text-decoration: none; }
    .icl-modal-open:hover { background: #8c1c27; }

    /* print: just the chart, fully expanded, no transform */
    @media print {
        body * { visibility: hidden; }
        #icl-geneao, #icl-geneao * { visibility: visible; }
        #icl-geneao { position: absolute; left: 0; top: 0; }
        .icl-toolbar, .icl-modal-backdrop { display: none !important; }
        .icl-viewport { overflow: visible; height: auto; min-height: 0; border: none; background: #fff; }
        .icl-stage { position: static; transform: none !important; transition: none; }
    }
</style>
</x-filament-panels::page>
