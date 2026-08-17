<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\MemberWallet;
use App\Models\SocialPost;
use App\Models\SocialPostComment;
use App\Support\Translatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Community (Phase 5a) — HQ announcement feed for the app, with app-user engagement.
 * Members see 'public' + 'members' posts; retail customers see 'public' only. Likes
 * and comments come from the signed-in identity (Member or Customer) via polymorphic
 * tables, so no admin User login is needed.
 */
class CommunityController extends Controller
{
    /** GET /community/feed — visible posts, pinned first then newest. */
    public function feed(Request $request): JsonResponse
    {
        $posts = $this->visible($request)
            ->with(['author', 'poster', 'media'])
            ->withCount(['reactions', 'appComments'])
            ->orderByDesc('pinned')
            ->orderByDesc('created_at')
            ->paginate(15)
            ->through(fn (SocialPost $p) => $this->present($p, $request));

        return response()->json($posts);
    }

    /** POST /community/posts — a member shares a post to the team feed. */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        // The community feed is the closed members' space; retail customers can read but not post.
        abort_unless($user instanceof Member, 403, 'Only members can post to the community.');

        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:4000'],
            'visibility' => ['nullable', 'in:public,members'],
            'photo' => ['nullable', 'image', 'max:8192'],   // item 8: post with picture
        ]);

        $post = SocialPost::create([
            'poster_type' => $user->getMorphClass(),
            'poster_id' => $user->getKey(),
            'title' => $data['title'] ?? null,
            'body' => $data['body'],
            'visibility' => $data['visibility'] ?? 'members',
        ]);

        if ($request->hasFile('photo')) {
            $post->media()->create([
                'path' => $request->file('photo')->store('community', 'public'),
                'type' => 'image',
            ]);
        }

        return response()->json(['data' => $this->present($post->load(['poster', 'media']), $request)], 201);
    }

    /** DELETE /community/posts/{post} — a member removes their own post. */
    public function destroy(Request $request, SocialPost $post): JsonResponse
    {
        abort_unless($this->ownedBy($request, $post), 404);
        $post->delete();

        return response()->json(['ok' => true]);
    }

    /** GET /community/posts/{post} — a single post. */
    public function show(Request $request, SocialPost $post): JsonResponse
    {
        abort_unless($this->canSee($request, $post), 404);
        $post->loadCount(['reactions', 'appComments'])->load(['author', 'poster']);

        return response()->json(['data' => $this->present($post, $request)]);
    }

    /** POST /community/posts/{post}/like — toggle the signed-in user's like. */
    public function toggleLike(Request $request, SocialPost $post): JsonResponse
    {
        abort_unless($this->canSee($request, $post), 404);
        $user = $request->user();

        $existing = $post->reactions()
            ->where('reactor_type', $user->getMorphClass())
            ->where('reactor_id', $user->getKey())
            ->first();

        if ($existing) {
            $existing->delete();
            $liked = false;
        } else {
            $post->reactions()->create([
                'reactor_type' => $user->getMorphClass(),
                'reactor_id' => $user->getKey(),
                'type' => 'like',
            ]);
            $liked = true;
        }

        return response()->json(['liked' => $liked, 'like_count' => $post->reactions()->count()]);
    }

    /** GET /community/posts/{post}/comments — comments, oldest first. */
    public function comments(Request $request, SocialPost $post): JsonResponse
    {
        abort_unless($this->canSee($request, $post), 404);

        $comments = $post->appComments()->where('is_hidden', false)->with('author')->orderBy('created_at')
            ->paginate(30)
            ->through(fn (SocialPostComment $c) => $this->presentComment($c));

        return response()->json($comments);
    }

    /** POST /community/posts/{post}/comments — add a comment. */
    public function addComment(Request $request, SocialPost $post): JsonResponse
    {
        abort_unless($this->canSee($request, $post), 404);
        $data = $request->validate(['body' => ['required', 'string', 'max:2000']]);
        $user = $request->user();

        $comment = $post->appComments()->create([
            'author_type' => $user->getMorphClass(),
            'author_id' => $user->getKey(),
            'body' => $data['body'],
        ]);

        return response()->json(['data' => $this->presentComment($comment->load('author'))], 201);
    }

    /**
     * GET /community/leaderboard?metric=bv|gbv|team|earnings — top members for a
     * metric, plus the caller's own standing (even if outside the top list).
     * Member-only — leaderboards rank the MLM network.
     */
    public function leaderboard(Request $request): JsonResponse
    {
        $me = $request->user();
        abort_unless($me instanceof Member, 403, 'Leaderboards are for members.');

        $metric = in_array($request->query('metric'), ['bv', 'gbv', 'team', 'earnings'], true)
            ? $request->query('metric') : 'bv';

        // Rank expression per metric (earnings needs the wallet join). Use the bare
        // column — it carries numeric affinity so comparisons sort numerically; wrapping
        // it (COALESCE/arithmetic) drops affinity and makes sqlite compare it as text.
        $valueExpr = match ($metric) {
            'gbv' => 'members.gbv',
            'team' => 'members.downline_count',
            'earnings' => 'mw.earning_total',
            default => 'members.bv',
        };

        $base = fn () => Member::query()
            ->where('members.status', 'active')
            ->when($metric === 'earnings', fn ($q) => $q->leftJoin('member_wallets as mw', 'mw.member_id', '=', 'members.id'));

        $top = $base()
            ->with('rank')
            ->select('members.*')
            ->selectRaw("$valueExpr as metric_value")
            ->orderByDesc('metric_value')
            ->orderBy('members.id')
            ->limit(20)
            ->get()
            ->values()
            ->map(fn (Member $m, int $i) => $this->presentRank($m, $i + 1, $me, (float) $m->metric_value, $metric));

        // The caller's value + global rank (1 + how many active members rank above them).
        $myValue = $this->metricValue($me, $metric);
        $myRank = $base()->whereRaw("$valueExpr > ?", [$myValue])->count() + 1;

        return response()->json([
            'metric' => $metric,
            'me' => [
                'rank' => $myRank,
                'value' => $myValue,
                'member_code' => $me->member_code,
                'name' => $me->name,
            ],
            'top' => $top,
        ]);
    }

    /**
     * GET /community/champions — the champion list BY RANK for the Info screen
     * (item 19, board 2026-08-12): every active rank from Corporate Director down,
     * with its top active members by BV. Open to members AND customers — it is a
     * motivational showcase, not network data.
     */
    public function champions(): JsonResponse
    {
        $ranks = \App\Models\Rank::where('is_active', true)
            ->where('depth', '>', 0)                 // MEMBER (depth 0) is not a "champion" tier
            ->orderByDesc('depth')
            ->get()
            ->map(fn (\App\Models\Rank $rank) => [
                'rank' => \App\Support\Translatable::pick($rank->name) ?: $rank->code,
                'depth' => (int) $rank->depth,
                'members' => Member::where('status', 'active')
                    ->where('rank_id', $rank->id)
                    ->orderByDesc('bv')
                    ->orderBy('id')
                    ->limit(5)
                    ->get()
                    ->map(fn (Member $m) => [
                        'member_code' => $m->member_code,
                        'name' => $m->name,
                        'city' => $m->city,
                        'bv' => (float) $m->bv,
                    ])->values(),
            ])
            ->filter(fn (array $tier) => $tier['members']->isNotEmpty())
            ->values();

        return response()->json(['data' => $ranks]);
    }

    protected function metricValue(Member $m, string $metric): float
    {
        return match ($metric) {
            'gbv' => (float) $m->gbv,
            'team' => (float) $m->downline_count,
            'earnings' => (float) (MemberWallet::where('member_id', $m->id)->value('earning_total') ?? 0),
            default => (float) $m->bv,
        };
    }

    protected function presentRank(Member $m, int $rank, Member $me, float $value, string $metric = 'bv'): array
    {
        $mine = (int) $m->id === (int) $me->id;

        return [
            'rank' => $rank,
            'member_code' => $m->member_code,
            'name' => $m->name,
            // Every row carries a TBP title so the ranking column is never empty;
            // `titled` marks real stage-holders (depth ≥ 1) for the gold treatment.
            'tier' => Translatable::pick($m->rank?->name) ?: 'Distributor',
            'titled' => (bool) ($m->rank && (int) $m->rank->depth > 0),
            // Income is private — on the earnings board others see the ORDER, not
            // the amount; only your own row keeps its number.
            'value' => ($metric === 'earnings' && ! $mine) ? null : round($value, 2),
            'mine' => $mine,
        ];
    }

    // ── helpers ─────────────────────────────────────────────────────────

    /** Base query of posts the signed-in identity may see. */
    protected function visible(Request $request)
    {
        $levels = $request->user() instanceof Member ? ['public', 'members'] : ['public'];

        // Hidden = moderated away by admin (item 8) — invisible in the app, kept in DB.
        return SocialPost::published()->whereIn('visibility', $levels)->where('is_hidden', false);
    }

    protected function canSee(Request $request, SocialPost $post): bool
    {
        $levels = $request->user() instanceof Member ? ['public', 'members'] : ['public'];

        return in_array($post->visibility, $levels, true)
            && ! $post->is_hidden
            && ($post->published_at === null || $post->published_at->lte(now()));
    }

    /** A post the signed-in identity authored (member team-feed post). */
    protected function ownedBy(Request $request, SocialPost $post): bool
    {
        $user = $request->user();

        return $post->poster_type === $user->getMorphClass()
            && (int) $post->poster_id === (int) $user->getKey();
    }

    protected function present(SocialPost $post, Request $request): array
    {
        $user = $request->user();
        $isMemberPost = $post->poster_id !== null;

        return [
            'id' => $post->id,
            'title' => $post->title,
            'body' => $post->body,
            'pinned' => (bool) $post->pinned,
            // Member posts show the member's name; HQ announcements show the brand.
            'author' => $isMemberPost ? ($post->poster?->name ?: 'Member') : ($post->author?->name ?: 'Lord ICL'),
            'kind' => $isMemberPost ? 'member' : 'announcement',
            'mine' => $this->ownedBy($request, $post),
            'visibility' => $post->visibility,
            // Post pictures (item 8) — request-host URLs so LAN phones resolve them.
            'images' => $post->media->where('type', 'image')->sortBy('sort')
                ->map(fn ($m) => media_url($m->path))->filter()->values(),
            'like_count' => (int) ($post->reactions_count ?? $post->reactions()->count()),
            'comment_count' => (int) ($post->app_comments_count ?? $post->appComments()->count()),
            'liked' => $post->reactions()
                ->where('reactor_type', $user->getMorphClass())
                ->where('reactor_id', $user->getKey())
                ->exists(),
            'created_at' => optional($post->created_at)->toIso8601String(),
        ];
    }

    protected function presentComment(SocialPostComment $c): array
    {
        return [
            'id' => $c->id,
            'author' => $c->author?->name ?: 'Member',
            'body' => $c->body,
            'created_at' => optional($c->created_at)->toIso8601String(),
        ];
    }
}
