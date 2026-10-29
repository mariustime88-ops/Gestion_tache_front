<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $notifications = $user->notifications()->latest()->limit(30)->get();
        $taskIds = $notifications
            ->filter(fn (DatabaseNotification $notification): bool => ($notification->data['title'] ?? null) === 'Nouvelle tâche')
            ->pluck('data.task_id')
            ->filter()
            ->unique()
            ->values();
        $startableTaskIds = Task::query()
            ->whereIn('id', $taskIds)
            ->where('assigned_to', $user->id)
            ->whereIn('status', ['assigned', 'needs_revision'])
            ->pluck('id')
            ->all();

        $notifications->each(function (DatabaseNotification $notification) use ($startableTaskIds): void {
            $notification->data = [
                ...$notification->data,
                'can_start' => ($notification->data['title'] ?? null) === 'Nouvelle tâche'
                    && in_array($notification->data['task_id'] ?? null, $startableTaskIds),
            ];
        });

        return response()->json([
            'data' => $notifications,
            'unread_count' => $user->unreadNotifications()->count(),
        ]);
    }

    public function markRead(Request $request, DatabaseNotification $notification): JsonResponse
    {
        abort_unless(
            $notification->notifiable_type === User::class
                && $notification->notifiable_id === $request->user()->id,
            404,
        );

        $notification->markAsRead();

        return response()->json(['message' => 'Notification marquée comme lue.']);
    }
}
