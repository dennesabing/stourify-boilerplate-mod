<?php

namespace Modules\Stourify\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Social\Models\Comment;
use Modules\Social\Models\Post;
use Modules\Stourify\Http\Notifications\ContentWarningNotification;
use Modules\Stourify\Models\Report;

class ModerationController extends Controller
{
    public function queue(Request $request): Response
    {
        $reports = Report::query()
            ->where('status', 'pending')
            ->when($request->type, fn ($q, $t) => $q->where('reportable_type', $t === 'post' ? Post::class : Comment::class))
            ->with('reporter:id,name')
            ->selectRaw('reportable_type, reportable_id, count(*) as report_count, max(created_at) as latest_report_at, group_concat(distinct reason) as reasons, min(id) as id')
            ->groupBy('reportable_type', 'reportable_id')
            ->orderByDesc('report_count')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Admin/Moderation/Queue', [
            'reports' => $reports,
            'filters' => $request->only(['type']),
        ]);
    }

    public function posts(Request $request): Response
    {
        $posts = Post::withoutGlobalScopes()
            ->with(['user:id,name'])
            ->when($request->search, fn ($q, $s) => $q->where(function ($q) use ($s) {
                $q->where('caption', 'like', "%{$s}%")
                  ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$s}%"));
            }))
            ->when($request->visibility, fn ($q, $v) => $q->where('visibility', $v))
            ->latest()
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Admin/Moderation/Posts', [
            'posts'   => $posts,
            'filters' => $request->only(['search', 'visibility']),
        ]);
    }

    public function comments(Request $request): Response
    {
        $comments = Comment::query()
            ->with(['user:id,name', 'post:id,uuid'])
            ->when($request->search, fn ($q, $s) => $q->where(function ($q) use ($s) {
                $q->where('body', 'like', "%{$s}%")
                  ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$s}%"));
            }))
            ->latest()
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Admin/Moderation/Comments', [
            'comments' => $comments,
            'filters'  => $request->only(['search']),
        ]);
    }

    public function dismiss(Report $report): RedirectResponse
    {
        Report::where('reportable_type', $report->reportable_type)
            ->where('reportable_id', $report->reportable_id)
            ->where('status', 'pending')
            ->update(['status' => 'dismissed']);

        return back()->with('success', 'Report dismissed.');
    }

    public function deleteAndWarn(Request $request, string $type, int $id): RedirectResponse
    {
        abort_if(! in_array($type, ['post', 'comment'], true), 422);

        if ($type === 'post') {
            $content = Post::withoutGlobalScopes()->findOrFail($id);
            $author  = $content->user;
            $content->clearCache();
            $content->delete();
        } else {
            $content = Comment::findOrFail($id);
            $author  = $content->user;
            $content->delete();
        }

        if ($author) {
            $author->notify(new ContentWarningNotification(contentType: $type));
        }

        Report::where('reportable_type', $type === 'post' ? Post::class : Comment::class)
            ->where('reportable_id', $id)
            ->update(['status' => 'actioned']);

        return back()->with('success', 'Content removed and user warned.');
    }
}
