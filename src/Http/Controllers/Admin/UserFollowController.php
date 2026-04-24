<?php

namespace Modules\Stourify\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class UserFollowController extends Controller
{
    public function show(User $user): JsonResponse
    {
        return response()->json(['followers' => [], 'following' => []]);
    }
}
