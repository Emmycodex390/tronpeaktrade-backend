<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * Generic in-app notification for admin alerts (new registration, new
 * login, chat message, investment payment claimed, etc). Deliberately
 * NOT ShouldQueue — there's no persistent queue worker running on the
 * free-tier host, so a queued notification would just sit forever
 * un-dispatched. Database-only: push is handled separately by
 * PushService, which has its own delivery path and failure handling.
 */
class AdminAlertNotification extends Notification
{
    public function __construct(
        private string $title,
        private string $message,
        private ?string $url = null
    ) {}

    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toArray($notifiable): array
    {
        return [
            'title' => $this->title,
            'message' => $this->message,
            'url' => $this->url,
        ];
    }
}
