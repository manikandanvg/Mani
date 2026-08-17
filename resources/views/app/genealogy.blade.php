<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
<title>My Network</title>
<script defer src="https://unpkg.com/alpinejs@3.14.1/dist/cdn.min.js"></script>
<style>
    [x-cloak] { display: none !important; }
    * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
    html, body { margin: 0; height: 100%; overflow: hidden; font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
    body { background: radial-gradient(circle at 20% 0%, #fff8ef, #ffffff); }
    #icl-geneao { --line: #e6ad46; height: 100%; display: flex; flex-direction: column; }

    .icl-toolbar { display: flex; gap: .4rem; align-items: center; flex-wrap: wrap; padding: .55rem .6rem; background: rgba(255,255,255,.92);
        border-bottom: 1px solid #f0e2ce; position: relative; z-index: 20; }
    .icl-searchwrap { position: relative; flex: 1 1 150px; }
    .icl-search { width: 100%; padding: .5rem .7rem; border: 1px solid #d1d5db; border-radius: .55rem; font-size: .85rem; }
    .icl-results { position: absolute; top: 110%; left: 0; z-index: 25; width: min(280px, 78vw); background: #fff; border: 1px solid #e5e7eb;
        border-radius: .6rem; box-shadow: 0 12px 30px rgba(0,0,0,.18); overflow: hidden; }
    .icl-results-head { font-size: .7rem; font-weight: 700; color: #92400e; background: #fdeccf; padding: .35rem .7rem; }
    .icl-results-list { max-height: 230px; overflow-y: auto; }
    .icl-result { display: flex; align-items: center; gap: .55rem; width: 100%; text-align: left;
        padding: .45rem .7rem; border: none; border-top: 1px solid #f1f1f1; background: none; font-size: .8rem; }
    .icl-result-ava { flex: 0 0 auto; width: 26px; height: 26px; border-radius: 50%; display: flex; align-items: center; justify-content: center;
        color: #fff; font-weight: 700; font-size: .75rem; background: linear-gradient(135deg, #ab222f, #e6ad46); }
    .icl-result-meta { display: flex; flex-direction: column; line-height: 1.15; overflow: hidden; }
    .icl-result-meta b { font-weight: 600; white-space: nowrap; }
    .icl-result-meta small { font-size: .68rem; color: #9ca3af; }
    .icl-result-off { opacity: .55; }
    .icl-notfound { position: absolute; top: 110%; left: 0; font-size: .72rem; color: #ab222f; font-weight: 600; }

    .icl-btn { padding: .5rem .7rem; border: 1px solid #ab222f; background: #ab222f; color: #fff; border-radius: .55rem; font-size: .82rem; font-weight: 600; line-height: 1; }
    .icl-btn-ghost { background: #fff; color: #ab222f; }

    .icl-viewport { position: relative; overflow: hidden; flex: 1; cursor: grab; touch-action: none; }
    .icl-stage { position: absolute; top: 30px; left: 50%; transform-origin: top center; will-change: transform;
        transition: transform .3s cubic-bezier(.22,.61,.36,1); }
    .icl-stage.icl-dragging { transition: none; }

    .icl-tree ul { display: flex; justify-content: center; padding: 26px 0 0; margin: 0; list-style: none; position: relative; }
    .icl-tree li { display: flex; flex-direction: column; align-items: center; list-style: none; position: relative; padding: 26px 12px 0; }
    .icl-tree li::before, .icl-tree li::after { content: ''; position: absolute; top: 0; right: 50%; width: 50%; height: 26px; border-top: 2px solid var(--line); }
    .icl-tree li::after { right: auto; left: 50%; border-left: 2px solid var(--line); }
    .icl-tree li:only-child::before, .icl-tree li:only-child::after { display: none; }
    .icl-tree li:only-child { padding-top: 0; }
    .icl-tree li:first-child::before, .icl-tree li:last-child::after { border: 0 none; }
    .icl-tree li:last-child::before { border-right: 2px solid var(--line); border-radius: 0 6px 0 0; }
    .icl-tree li:first-child::after { border-radius: 6px 0 0 0; }
    .icl-tree ul ul::before { content: ''; position: absolute; top: 0; left: 50%; width: 0; height: 26px; border-left: 2px solid var(--line); }

    .icl-card { display: flex; align-items: center; gap: .55rem; background: #fff; border: 1px solid #e5e7eb; border-radius: .85rem;
        padding: .55rem .75rem; box-shadow: 0 1px 3px rgba(0,0,0,.08); min-width: 185px; position: relative;
        transition: box-shadow .18s, border-color .18s, opacity .25s, transform .12s; }
    .icl-card:active { transform: scale(.97); }
    .icl-ava { width: 40px; height: 40px; border-radius: 50%; flex: 0 0 auto; display: flex; align-items: center; justify-content: center;
        color: #fff; font-weight: 700; font-size: 1rem; background: linear-gradient(135deg, #ab222f, #e6ad46); position: relative; }
    .icl-verified { position: absolute; right: -4px; bottom: -3px; width: 16px; height: 16px; border-radius: 50%;
        background: #059669; color: #fff; font-size: .62rem; font-weight: 800; display: flex; align-items: center;
        justify-content: center; border: 2px solid #fff; line-height: 1; }
    .icl-meta { text-align: left; }
    .icl-name { font-weight: 600; font-size: .88rem; line-height: 1.15; color: #111827; }
    .icl-code { font-size: .7rem; color: #6b7280; }
    .icl-pos { display: inline-block; margin-top: 3px; font-size: .62rem; font-weight: 600; color: #92400e;
        background: #fdeccf; border: 1px solid #e6ad46; padding: 1px 7px; border-radius: 999px; }
    .icl-toggle { position: absolute; left: 50%; bottom: -13px; transform: translateX(-50%); z-index: 2; display: flex; align-items: center; gap: 4px;
        background: #ab222f; color: #fff; border: 2px solid #fff; border-radius: 999px; font-size: .72rem; line-height: 1; padding: 3px 10px; box-shadow: 0 1px 3px rgba(0,0,0,.25); }
    .icl-count { font-weight: 700; }

    .icl-inactive { opacity: .55; }
    .icl-inactive .icl-ava { background: #9ca3af; }
    .icl-card.icl-dim { opacity: .2; filter: grayscale(1); }
    .icl-card.icl-hit { outline: 3px solid #e6ad46; outline-offset: 2px; }
    .icl-card.icl-current { outline: 3px solid #ab222f; outline-offset: 2px; box-shadow: 0 0 0 6px rgba(171,34,47,.15); }

    .icl-modal-backdrop { position: fixed; inset: 0; background: rgba(17,24,39,.45); display: flex; align-items: center; justify-content: center; z-index: 30; }
    .icl-modal { position: relative; width: 320px; max-width: 88%; background: #fff; border-radius: 1rem; padding: 1.2rem; box-shadow: 0 20px 50px rgba(0,0,0,.3); }
    .icl-modal-x { position: absolute; top: .4rem; right: .65rem; border: none; background: none; font-size: 1.5rem; line-height: 1; color: #9ca3af; }
    .icl-modal-head { display: flex; align-items: center; gap: .75rem; margin-bottom: .9rem; }
    .icl-ava-lg { width: 54px; height: 54px; font-size: 1.35rem; }
    .icl-modal-name { font-weight: 700; font-size: 1.02rem; }
    .icl-modal-code { font-size: .8rem; color: #6b7280; }
    .icl-modal-rows > div { display: flex; justify-content: space-between; padding: .45rem 0; border-top: 1px solid #f0f0f0; font-size: .85rem; }
    .icl-modal-rows span { color: #6b7280; }
    .icl-ok { color: #059669; } .icl-off { color: #ab222f; }
</style>
</head>
<body>
<div id="icl-geneao"
     x-data="{
        z: 1, x: 0, y: 0, dragging: false, sx: 0, sy: 0, q: '',
        selected: {}, modal: false, notFound: false, matches: [],
        pointers: {}, pinchDist: 0, pinchZ: 1,
        zoomIn() { this.z = Math.min(2.5, this.z + 0.15) },
        zoomOut() { this.z = Math.max(0.25, this.z - 0.15) },
        reset() { this.z = 1; this.x = 0; this.y = 0 },
        down(e) {
            this.pointers[e.pointerId] = { x: e.clientX, y: e.clientY };
            const pts = Object.values(this.pointers);
            if (pts.length === 2) {                        // pinch begins
                this.dragging = false;
                this.pinchDist = Math.hypot(pts[0].x - pts[1].x, pts[0].y - pts[1].y);
                this.pinchZ = this.z;
            } else {
                this.dragging = true; this.sx = e.clientX - this.x; this.sy = e.clientY - this.y;
            }
        },
        moveP(e) {
            if (! this.pointers[e.pointerId]) return this.dragging && this.drag(e);
            this.pointers[e.pointerId] = { x: e.clientX, y: e.clientY };
            const pts = Object.values(this.pointers);
            if (pts.length === 2 && this.pinchDist > 0) {
                const d = Math.hypot(pts[0].x - pts[1].x, pts[0].y - pts[1].y);
                this.z = Math.min(2.5, Math.max(0.25, this.pinchZ * d / this.pinchDist));
            } else if (this.dragging) { this.drag(e); }
        },
        drag(e) { this.x = e.clientX - this.sx; this.y = e.clientY - this.sy },
        up(e) {
            delete this.pointers[e.pointerId];
            if (Object.keys(this.pointers).length < 2) this.pinchDist = 0;
            if (Object.keys(this.pointers).length === 0) this.dragging = false;
        },
        wheel(e) { this.z = Math.min(2.5, Math.max(0.25, this.z + (e.deltaY < 0 ? 0.1 : -0.1))) },
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
                count: el.dataset.count, active: el.dataset.active === '1',
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
                    if (this.matches.length < 100) this.matches.push({
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
        }
     }">

    <div class="icl-toolbar">
        <div class="icl-searchwrap">
            <input type="text" class="icl-search" placeholder="Search name or code…"
                   x-model="q" @input.debounce.200ms="$dispatch('icl-expand'); filter()">
            <span class="icl-notfound" x-show="notFound" x-cloak x-text="`No match for “${q}”`"></span>
            <div class="icl-results" x-show="q && matches.length" x-cloak>
                <div class="icl-results-head" x-text="matches.length + (matches.length === 1 ? ' match' : ' matches')"></div>
                <div class="icl-results-list">
                    <template x-for="m in matches" :key="m.code">
                        <button type="button" class="icl-result" @click="goTo(m.code)" :class="{ 'icl-result-off': ! m.active }">
                            <span class="icl-result-ava" x-text="m.initial"></span>
                            <span class="icl-result-meta">
                                <b x-text="m.name"></b>
                                <small><span x-text="m.code"></span></small>
                            </span>
                        </button>
                    </template>
                </div>
            </div>
        </div>
        <button type="button" class="icl-btn icl-btn-ghost" @click="$dispatch('icl-expand')">Expand</button>
        <button type="button" class="icl-btn icl-btn-ghost" @click="$dispatch('icl-collapse')">Collapse</button>
        <button type="button" class="icl-btn" @click="zoomOut()">−</button>
        <button type="button" class="icl-btn" @click="reset()">⟲</button>
        <button type="button" class="icl-btn" @click="zoomIn()">+</button>
    </div>

    <div class="icl-viewport" x-ref="viewport"
         @pointerdown="down" @pointermove="moveP" @pointerup="up" @pointercancel="up" @pointerleave="up"
         @wheel.prevent="wheel($event)">
        <div class="icl-stage" :class="{ 'icl-dragging': dragging }"
             :style="`transform: translate(${x}px, ${y}px) scale(${z});`">
            <div class="icl-tree">
                <ul>
                    @foreach ($tree as $node)
                        @include('filament.pages.partials.genealogy-node', ['node' => $node, 'depth' => 0])
                    @endforeach
                </ul>
            </div>
        </div>

        <div class="icl-modal-backdrop" x-show="modal" x-cloak
             @click.self="modal = false">
            <div class="icl-modal" x-show="modal">
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
            </div>
        </div>
    </div>
</div>
</body>
</html>
