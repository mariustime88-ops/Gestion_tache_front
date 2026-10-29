<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TaskNotification extends Notification
{
    use Queueable;

    public function __construct(
        public string $title,
        public string $message,
        public ?int $taskId,
    ) {}

    public function via(object $notifiable): array
    {
        return config('tasks.mail_notifications') && ! empty($notifiable->email)
            ? ['database', 'mail']
            : ['database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->title)
            ->greeting('Bonjour '.($notifiable->name ?? '').',')
            ->line($this->message)
            ->line('Connectez-vous à la plateforme pour consulter la tâche.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title' => $this->title,
            'message' => $this->message,
            'task_id' => $this->taskId,
        ];
    }
}
