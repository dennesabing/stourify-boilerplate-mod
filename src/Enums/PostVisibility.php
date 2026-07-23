<?php

declare(strict_types=1);

namespace Modules\Stourify\Enums;

/**
 * Who can see a post. Mirrors the Review & Publish screen's visibility picker.
 */
enum PostVisibility: string
{
    case Public = 'public';
    case Followers = 'followers';
    case Private = 'private';
}
