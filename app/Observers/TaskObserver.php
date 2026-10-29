<?php

namespace App\Observers;

use App\Models\AuditLog;
use App\Models\Task;

class TaskObserver
{
    public function created(Task $task): void
    {
        AuditLog::record('task.created', $task, 'Création de la tâche « '.$task->title.' ».', [
            'status' => ['old' => null, 'new' => $task->status],
            'assigned_to' => ['old' => null, 'new' => $task->assigned_to],
        ]);
    }

    public function updated(Task $task): void
    {
        $changes = [];

        foreach ($task->getChanges() as $field => $newValue) {
            if ($field === 'updated_at') {
                continue;
            }

            $changes[$field] = [
                'old' => $task->getOriginal($field),
                'new' => $newValue,
            ];
        }

        if ($changes === []) {
            return;
        }

        $action = array_key_exists('status', $changes) ? 'task.status_changed' : 'task.updated';

        AuditLog::record($action, $task, 'Modification de la tâche « '.$task->title.' ».', $changes);
    }

    public function deleted(Task $task): void
    {
        AuditLog::record('task.deleted', $task, 'Suppression de la tâche « '.$task->title.' ».');
    }
}
