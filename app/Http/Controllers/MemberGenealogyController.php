<?php

namespace App\Http\Controllers;

use App\Models\Member;
use App\Support\Translatable;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Member genealogy as a WEB page (board 2026-08-12 app item 12): the SAME
 * hand-built org chart the admin panel uses, scoped to the signed-in member's
 * subtree and rendered standalone for the app's WebView. Auth is a signed URL
 * minted by the API (like zoom.join) — no session/cookie needed in the WebView.
 */
class MemberGenealogyController extends Controller
{
    public function show(Request $request, Member $member): View
    {
        abort_unless($request->hasValidSignature(), 403);

        return view('app.genealogy', [
            'tree' => [$this->subtree($member)],
            'memberName' => $member->name,
        ]);
    }

    /** Same node shape as Filament\Pages\GenealogyTree::getTree, rooted at $member. */
    protected function subtree(Member $root): array
    {
        $locale = Translatable::defaultLocale();

        $members = Member::query()
            ->select('id', 'member_code', 'name', 'upline_id', 'rank_id', 'status', 'pan_verified', 'aadhaar_verified')
            ->with('rank:id,name')
            ->orderBy('id')
            ->get();

        $byParent = $members->groupBy('upline_id');

        $build = function (Member $m) use (&$build, $byParent, $locale) {
            $children = ($byParent->get($m->id) ?? collect())->map($build)->all();
            $descendants = count($children) + array_sum(array_column($children, 'descendants'));

            return [
                'id' => $m->id,
                'name' => $m->name,
                'code' => $m->member_code,
                'position' => $m->rank ? Translatable::pick($m->rank->name, $locale) : '',
                'active' => $m->status === 'active',
                'verified' => $m->kyc_verified,
                'initial' => strtoupper(mb_substr(trim($m->name) !== '' ? trim($m->name) : '?', 0, 1)),
                'url' => '',   // no admin record links for members
                'children' => $children,
                'descendants' => $descendants,
            ];
        };

        return $build($members->firstWhere('id', $root->id) ?? $root);
    }
}
