<?php

namespace Modules\Stourify\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Modules\Social\Models\Follow;

class UserFollowController extends Controller
{
    public function show(User $user): JsonResponse
    {
        $followers = Follow::where('followee_id', $user->id)
            ->where('status', 'active')
            ->with('follower:id,uuid,name,email')
            ->latest()
            ->get()
            ->map(fn ($f) => $f->follower)
            ->filter()
            ->values();

        $following = Follow::where('follower_id', $user->id)
            ->where('status', 'active')
            ->with('followee:id,uuid,name,email')
            ->latest()
            ->get()
            ->map(fn ($f) => $f->followee)
            ->filter()
            ->values();

        return response()->json([
            'followers' => $followers,
            'following' => $following,
        ]);
    }
}
