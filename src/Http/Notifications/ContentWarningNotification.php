<?php

namespace Modules\Stourify\Http\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class ContentWarningNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $contentType,
        public readonly string $reason = 'Your content was removed by a moderator.',
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type'         => 'content_warning',
            'content_type' => $this->contentType,
            'message'      => $this->reason,
        ];
    }
}
