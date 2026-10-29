<?php

namespace App\Console\Commands;

use App\Models\Task;
use App\Notifications\TaskNotification;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('tasks:send-reminders')]
#[Description('Envoie les rappels J-2, Jour J, retards et relances de validation (à exécuter chaque jour).')]
class SendTaskReminders extends Command
{
    public function handle(): int
    {
        $sent = 0;

        // Rappel à J-2 : échéance dans deux jours.
        Task::query()
            ->whereIn('status', Task::OVERDUE_STATUSES)
            ->whereNotNull('assigned_to')
            ->whereDate('due_at', now()->addDays(2)->toDateString())
            ->whereNull('reminded_d2_at')
            ->get()
            ->each(function (Task $task) use (&$sent): void {
                $task->assignee?->notify(new TaskNotification(
                    'Échéance dans 2 jours',
                    'La date limite approche pour : '.$task->title,
                    $task->id,
                ));
                Task::whereKey($task->id)->update(['reminded_d2_at' => now()]);
                $sent++;
            });

        // Rappel du Jour J.
        Task::query()
            ->whereIn('status', Task::OVERDUE_STATUSES)
            ->whereNotNull('assigned_to')
            ->whereDate('due_at', now()->toDateString())
            ->whereNull('reminded_d0_at')
            ->get()
            ->each(function (Task $task) use (&$sent): void {
                $task->assignee?->notify(new TaskNotification(
                    'Échéance aujourd’hui',
                    'La date limite est aujourd’hui pour : '.$task->title,
                    $task->id,
                ));
                Task::whereKey($task->id)->update(['reminded_d0_at' => now()]);
                $sent++;
            });

        // Retard : alerte unique au destinataire et au donneur d’ordre.
        Task::query()
            ->whereIn('status', Task::OVERDUE_STATUSES)
            ->whereNotNull('due_at')
            ->where('due_at', '<', now())
            ->whereNull('overdue_notified_at')
            ->get()
            ->each(function (Task $task) use (&$sent): void {
                $message = 'La tâche est en retard : '.$task->title;
                $task->assignee?->notify(new TaskNotification('Tâche en retard', $message, $task->id));
                $task->creator?->notify(new TaskNotification('Tâche en retard', $message, $task->id));
                Task::whereKey($task->id)->update(['overdue_notified_at' => now()]);
                $sent++;
            });

        // Relance du donneur d’ordre après 3 jours sans validation d’une tâche terminée.
        Task::query()
            ->where('status', 'submitted')
            ->where('submitted_at', '<=', now()->subDays(3))
            ->where(function ($query): void {
                $query->whereNull('review_reminded_at')
                    ->orWhere('review_reminded_at', '<=', now()->subDays(3));
            })
            ->get()
            ->each(function (Task $task) use (&$sent): void {
                $task->creator?->notify(new TaskNotification(
                    'Validation en attente',
                    'Une tâche terminée attend votre validation depuis plus de 3 jours : '.$task->title,
                    $task->id,
                ));
                Task::whereKey($task->id)->update(['review_reminded_at' => now()]);
                $sent++;
            });

        $this->info($sent.' rappel(s) envoyé(s).');

        return self::SUCCESS;
    }
}
