<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class ProjectNotification extends Notification
{
    use Queueable;

    public function __construct(
        public string $title,
        public string $message,
        public int $projectId,
        public int $applicationId,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title' => $this->title,
            'message' => $this->message,
            'task_id' => null,
            'project_id' => $this->projectId,
            'application_id' => $this->applicationId,
        ];
    }
}
