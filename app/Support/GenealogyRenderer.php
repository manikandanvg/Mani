<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;

/**
 * Builds and renders the distributor genealogy for the admin page and the app WebView.
 *
 * With ~9k members (16k+ after roll-out) the old approach — 9k Eloquent models + one
 * recursive Blade @include per node — exhausted PHP's 128 MB and took ~10 s. This reads
 * plain rows, links them iteratively, and writes the markup into one output buffer. The
 * markup is byte-for-byte what the Alpine code on the pages expects (classes + data-*).
 */
class GenealogyRenderer
{
    /**
     * Nested node tree. $rootId = the whole network (roots = no / missing upline) or one
     * member's subtree. Node shape: id, name, code, position, active, verified, initial,
     * url, children[], descendants.
     */
    public static function tree(?int $rootId = null, bool $adminLinks = true): array
    {
        $locale = Translatable::defaultLocale();
        $rankNames = [];
        foreach (DB::table('ranks')->select('id', 'name')->get() as $r) {
            $rankNames[$r->id] = (string) Translatable::pick(json_decode($r->name, true) ?? $r->name, $locale);
        }

        $rows = DB::table('members')
            ->select('id', 'member_code', 'name', 'upline_id', 'rank_id', 'status', 'pan_verified', 'aadhaar_verified')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get();

        $nodes = [];
        foreach ($rows as $m) {
            $name = trim((string) $m->name);
            $nodes[$m->id] = [
                'id' => (int) $m->id,
                'name' => $m->name,
                'code' => $m->member_code,
                'position' => $m->rank_id ? ($rankNames[$m->rank_id] ?? '') : '',
                'active' => $m->status === 'active',
                'verified' => (bool) $m->pan_verified && (bool) $m->aadhaar_verified,
                'initial' => strtoupper(mb_substr($name !== '' ? $name : '?', 0, 1)),
                'url' => $adminLinks ? url('/admin/members/' . $m->id . '/edit') : '',
                'children' => [],
                'descendants' => 0,
                '_up' => $m->upline_id !== null && isset($nodes[$m->upline_id]) ? (int) $m->upline_id : null,
            ];
        }
        // uplines that come later in id order
        foreach ($rows as $m) {
            if ($m->upline_id !== null && isset($nodes[$m->upline_id])) {
                $nodes[$m->id]['_up'] = (int) $m->upline_id;
            }
        }

        // children lists (ids), roots
        $kids = [];
        $roots = [];
        foreach ($nodes as $id => $n) {
            if ($n['_up'] === null) {
                $roots[] = $id;
            } else {
                $kids[$n['_up']][] = $id;
            }
        }

        $build = function (int $id) use (&$build, &$nodes, &$kids): array {
            $node = $nodes[$id];
            unset($node['_up']);
            $count = 0;
            foreach ($kids[$id] ?? [] as $cid) {
                $child = $build($cid);
                $count += 1 + $child['descendants'];
                $node['children'][] = $child;
            }
            $node['descendants'] = $count;

            return $node;
        };

        if ($rootId !== null) {
            return isset($nodes[$rootId]) ? [$build($rootId)] : [];
        }

        return array_map($build, $roots);
    }

    /** Explorer-style folder rows (<details>/<summary>) — "Distributor Place". */
    public static function folder(array $tree): HtmlString
    {
        ob_start();
        echo '<svg width="0" height="0" style="position:absolute" aria-hidden="true"><defs>',
            '<symbol id="icl-ico-folder" viewBox="0 0 24 24"><path fill="#e6ad46" d="M3 6.5A2.5 2.5 0 0 1 5.5 4h4.2c.7 0 1.3.3 1.8.8L13 6.5h5.5A2.5 2.5 0 0 1 21 9v8.5a2.5 2.5 0 0 1-2.5 2.5h-13A2.5 2.5 0 0 1 3 17.5z"/><path fill="#f3d79a" d="M3 10h18v7.5a2.5 2.5 0 0 1-2.5 2.5h-13A2.5 2.5 0 0 1 3 17.5z"/></symbol>',
            '<symbol id="icl-ico-person" viewBox="0 0 24 24"><circle cx="12" cy="8" r="4" fill="#ab222f"/><path fill="#ab222f" d="M4 20a8 8 0 0 1 16 0z"/></symbol>',
            '</defs></svg>';
        foreach ($tree as $node) {
            self::folderNode($node, 0);
        }

        return new HtmlString(ob_get_clean());
    }

    /** Org-chart cards (<li> + .icl-card) for the chart view and the app WebView. */
    public static function chart(array $tree): HtmlString
    {
        ob_start();
        foreach ($tree as $node) {
            self::chartNode($node, 0);
        }

        return new HtmlString(ob_get_clean());
    }

    protected static function data(array $n): string
    {
        return ' data-search="' . e(strtolower($n['name'] . ' ' . $n['code'])) . '"'
            . ' data-name="' . e($n['name']) . '"'
            . ' data-code="' . e($n['code']) . '"'
            . ' data-position="' . e($n['position']) . '"'
            . ' data-count="' . (int) $n['descendants'] . '"'
            . ' data-active="' . ($n['active'] ? '1' : '0') . '"'
            . ' data-verified="' . (($n['verified'] ?? false) ? '1' : '0') . '"'
            . ' data-url="' . e($n['url']) . '"';
    }

    protected static function folderNode(array $n, int $depth): void
    {
        $kids = ! empty($n['children']);
        echo '<details class="icl-fo-node"', $depth < 1 ? ' open' : '', ' data-depth="', $depth, '">';
        echo '<summary class="icl-fo-row', $n['active'] ? '' : ' icl-fo-inactive', '"', self::data($n), ' style="--d:', $depth, '">';
        echo '<span class="icl-fo-caret" aria-hidden="true">', $kids ? '▸' : '', '</span>';
        echo '<svg class="icl-fo-svg" aria-hidden="true"><use href="#icl-ico-', $kids ? 'folder' : 'person', '"/></svg>';
        echo '<span class="icl-fo-name" @click.stop.prevent="pick($el.closest(\'.icl-fo-row\'))">', e($n['name']), '</span>';
        echo '<span class="icl-fo-code">', e($n['code']), '</span>';
        if ($n['position']) {
            echo '<span class="icl-fo-pos">', e($n['position']), '</span>';
        }
        if ($n['verified'] ?? false) {
            echo '<span class="icl-fo-kyc" title="KYC verified">✓ KYC</span>';
        }
        if (! $n['active']) {
            echo '<span class="icl-fo-off">inactive</span>';
        }
        if ($kids) {
            echo '<span class="icl-fo-count" title="Team size">', (int) $n['descendants'], '</span>';
        }
        echo '</summary>';
        if ($kids) {
            echo '<div class="icl-fo-children" style="--d:', $depth + 1, '">';
            foreach ($n['children'] as $c) {
                self::folderNode($c, $depth + 1);
            }
            echo '</div>';
        }
        echo '</details>';
    }

    protected static function chartNode(array $n, int $depth): void
    {
        $kids = ! empty($n['children']);
        echo '<li x-data="{ open: ', $depth < 2 ? 'true' : 'false', ' }" x-on:icl-collapse.window="open = false" x-on:icl-expand.window="open = true">';
        echo '<div class="icl-card', $n['active'] ? '' : ' icl-inactive', '"', self::data($n), ' @click="pick($el)">';
        echo '<div class="icl-ava">', e($n['initial']);
        if ($n['verified'] ?? false) {
            echo '<span class="icl-verified" title="KYC verified">✓</span>';
        }
        echo '</div><div class="icl-meta"><div class="icl-name">', e($n['name']), '</div><div class="icl-code">', e($n['code']), '</div>';
        if ($n['position']) {
            echo '<span class="icl-pos">', e($n['position']), '</span>';
        }
        echo '</div>';
        if ($kids) {
            echo '<button type="button" class="icl-toggle" @click.stop="open = ! open; $nextTick(() => centerOn($el.closest(\'.icl-card\')))" :title="open ? \'Collapse\' : \'Expand\'">';
            echo '<span x-text="open ? \'–\' : \'+\'"></span><span class="icl-count">', (int) $n['descendants'], '</span></button>';
        }
        echo '</div>';
        if ($kids) {
            echo '<ul x-show="open" x-transition.opacity.duration.150ms>';
            foreach ($n['children'] as $c) {
                self::chartNode($c, $depth + 1);
            }
            echo '</ul>';
        }
        echo '</li>';
    }
}
