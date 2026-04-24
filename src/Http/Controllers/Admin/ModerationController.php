<?php

namespace Modules\Stourify\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Stourify\Models\Report;

class ModerationController extends Controller
{
    public function queue(): Response
    {
        return Inertia::render('Admin/Moderation/Queue');
    }

    public function posts(): Response
    {
        return Inertia::render('Admin/Moderation/Posts');
    }

    public function comments(): Response
    {
        return Inertia::render('Admin/Moderation/Comments');
    }

    public function dismiss(Report $report)
    {
        return back();
    }

    public function deleteAndWarn(Request $request, string $type, int $id)
    {
        return back();
    }
}
