<?php

declare(strict_types=1);

namespace Modules\Stourify\Enums;

/**
 * A follow edge. `Pending` exists for private accounts (Settings → Privacy),
 * where a follow is a request until the followee accepts.
 */
enum FollowStatus: string
{
    case Active = 'active';
    case Pending = 'pending';
}
