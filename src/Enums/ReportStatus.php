<?php

declare(strict_types=1);

namespace Modules\Stourify\Enums;

enum ReportStatus: string
{
    case Pending = 'pending';
    case Reviewing = 'reviewing';
    case Actioned = 'actioned';
    case Dismissed = 'dismissed';
}
