<?php

declare(strict_types=1);

namespace Modules\Stourify\Enums;

/**
 * Lifecycle of a community-contributed spot.
 */
enum SpotStatus: string
{
    /** Created offline or mid-upload; not yet visible to anyone but its author. */
    case Draft = 'draft';

    /** Live and discoverable. */
    case Published = 'published';

    /** Hidden pending moderator review after a report. */
    case UnderReview = 'under_review';

    /** Removed by a moderator; retained for audit. */
    case Removed = 'removed';

    /**
     * Statuses a normal explorer may see in discovery surfaces.
     *
     * @return array<int, string>
     */
    public static function discoverable(): array
    {
        return [self::Published->value];
    }
}
