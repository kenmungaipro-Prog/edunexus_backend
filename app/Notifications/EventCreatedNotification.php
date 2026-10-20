<?php

namespace App\Notifications;

use App\Models\Event;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class EventCreatedNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly Event $event)
    {
    }

    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toDatabase($notifiable): array
    {
        return [
            'kind' => 'event_created',
            'event_id' => $this->event->id,
            'title' => $this->event->title,
            'event_date' => $this->event->event_date->toDateString(),
            'type' => $this->event->type,
            'message' => "New school event: {$this->event->title}",
            'url' => '/events',
        ];
    }

    public function toArray($notifiable): array
    {
        return $this->toDatabase($notifiable);
    }
}
