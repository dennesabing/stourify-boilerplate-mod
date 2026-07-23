<?php

declare(strict_types=1);

namespace Modules\Stourify\Enums;

/**
 * Report reasons, matching the Report Content screen in the Settings deck.
 */
enum ReportReason: string
{
    case Spam = 'spam';
    case Inappropriate = 'inappropriate';
    case WrongInfo = 'wrong_info';
    case Harassment = 'harassment';
    case Other = 'other';
}
