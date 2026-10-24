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

    /** Same node shape as the admin page (GenealogyRenderer::tree), rooted at $member, no admin links. */
    protected function subtree(Member $root): array
    {
        return \App\Support\GenealogyRenderer::tree($root->id, adminLinks: false)[0] ?? [
            'id' => $root->id, 'name' => $root->name, 'code' => $root->member_code, 'position' => '',
            'active' => $root->status === 'active', 'verified' => $root->kyc_verified,
            'initial' => strtoupper(mb_substr(trim((string) $root->name) !== '' ? trim($root->name) : '?', 0, 1)),
            'url' => '', 'children' => [], 'descendants' => 0,
        ];
    }
}
