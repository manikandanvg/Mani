<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\TaskAssignment;
use App\Models\TaskSubmission;
use App\Services\Tasks\TaskEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Monthly Tasks for the app (board 2026-08-29): My Status → Monthly Tasks block,
 * the branch's day-by-day stock chart, and proof submissions for manual tasks.
 */
class TaskController extends Controller
{
    public function __construct(protected TaskEngine $engine) {}

    /** GET /member/tasks?month=YYYY-MM */
    public function index(Request $request): JsonResponse
    {
        $member = $this->member($request);
        $month = $this->month($request);

        // Keep the numbers fresh for the person looking (the nightly job does the fleet).
        if ($month->isSameMonth(Carbon::now())) {
            TaskAssignment::with('taskType')->forMonth($month)->forSubject('member', $member->id)->whereNull('locked_at')
                ->each(fn (TaskAssignment $a) => $this->engine->measureAssignment($a));
            if ($branch = TaskEngine::branchRunBy($member)) {
                TaskAssignment::with('taskType')->forMonth($month)->forSubject('branch', $branch->id)->whereNull('locked_at')
                    ->each(fn (TaskAssignment $a) => $this->engine->measureAssignment($a));
                $this->engine->score('branch', $branch->id, $month);
            }
            if (TaskAssignment::forMonth($month)->forSubject('member', $member->id)->exists()) {
                $this->engine->score('member', $member->id, $month);
            }
        }

        return response()->json($this->engine->summary($member, $month));
    }

    /** GET /member/tasks/stock-chart?month=YYYY-MM&product=ID — the branch the member runs. */
    public function stockChart(Request $request): JsonResponse
    {
        $member = $this->member($request);
        $branch = TaskEngine::branchRunBy($member);
        abort_unless($branch, 404, 'You do not run a branch.');

        return response()->json($this->engine->stockChart($branch, $this->month($request), $request->integer('product') ?: null));
    }

    /** POST /member/tasks/{assignment}/submit — proof for a manual task (text, photo, GPS). */
    public function submit(Request $request, TaskAssignment $assignment): JsonResponse
    {
        $member = $this->member($request);
        abort_unless($assignment->subject_type === 'member' && (int) $assignment->subject_id === (int) $member->id, 403);
        abort_unless($assignment->taskType?->isManual(), 422, 'This task is measured automatically — nothing to submit.');
        abort_if($assignment->isLocked(), 422, 'This month is closed.');

        $data = $request->validate([
            'body' => ['nullable', 'string', 'max:2000'],
            'photo' => ['nullable', 'image', 'max:8192'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
        ]);
        abort_if(blank($data['body'] ?? null) && ! $request->hasFile('photo'), 422, 'Add a note or a photo.');

        $path = $request->hasFile('photo') ? $request->file('photo')->store('task-proof', 'local') : null;

        $row = TaskSubmission::create([
            'task_assignment_id' => $assignment->id,
            'member_id' => $member->id,
            'body' => $data['body'] ?? null,
            'photo_path' => $path,
            'lat' => $data['lat'] ?? null,
            'lng' => $data['lng'] ?? null,
            'status' => 'pending',
        ]);
        $this->engine->measureAssignment($assignment->fresh('taskType'));

        \App\Services\Push\Notifier::admins(
            'Task proof submitted',
            $member->member_code . ' — ' . $member->name . ' submitted proof for "' . $assignment->title() . '".',
            url: url('/admin/task-submissions'),
            category: 'system',
        );

        return response()->json(['ok' => true, 'submission_id' => $row->id, 'status' => 'pending'], 201);
    }

    protected function member(Request $request): Member
    {
        $user = $request->user();
        abort_unless($user instanceof Member, 403, 'Distributor login required.');

        return $user;
    }

    protected function month(Request $request): Carbon
    {
        $m = (string) $request->query('month', '');

        return preg_match('/^\d{4}-\d{2}$/', $m)
            ? Carbon::createFromFormat('Y-m', $m)->startOfMonth()->startOfDay()
            : Carbon::now()->startOfMonth()->startOfDay();
    }
}
