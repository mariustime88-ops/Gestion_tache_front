<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\ProjectApplication;
use App\Models\ProjectMessage;
use App\Models\User;
use App\Notifications\ProjectNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProjectApplicationController extends Controller
{
    public function store(Request $request, Project $project): JsonResponse
    {
        $applicant = $request->user();
        abort_unless($applicant->capabilityRole() === 'agent', 403);
        abort_unless(
            $project->status === 'open'
                && (! $project->deadline || $project->deadline->isToday() || $project->deadline->isFuture()),
            422,
            'Ce projet n’accepte plus de demandes.',
        );

        $data = $request->validate([
            'type' => ['required', Rule::in(['application', 'inquiry'])],
            'message' => ['required', 'string', 'max:5000'],
        ]);

        $application = DB::transaction(function () use ($applicant, $project, $data): ProjectApplication {
            $application = ProjectApplication::firstOrCreate(
                [
                    'project_id' => $project->id,
                    'applicant_id' => $applicant->id,
                    'type' => $data['type'],
                ],
                ['status' => 'pending'],
            );

            if (! $application->wasRecentlyCreated) {
                throw ValidationException::withMessages([
                    'type' => ['Vous avez déjà envoyé une demande de ce type pour ce projet.'],
                ]);
            }

            $application->messages()->create([
                'sender_id' => $applicant->id,
                'body' => $data['message'],
            ]);

            $project->owner->notify(new ProjectNotification(
                $data['type'] === 'application' ? 'Nouvelle candidature' : 'Nouvelle demande de contact',
                $applicant->name.' vous a contacté au sujet de « '.$project->title.' ».',
                $project->id,
                $application->id,
            ));

            return $application;
        });

        return response()->json([
            'data' => ['application' => $this->loadApplication($application)],
        ], 201);
    }

    public function show(Request $request, ProjectApplication $application): JsonResponse
    {
        $this->authorizeParticipant($request->user(), $application);

        return response()->json(['data' => ['application' => $this->loadApplication($application)]]);
    }

    public function sendMessage(Request $request, ProjectApplication $application): JsonResponse
    {
        $sender = $request->user();
        $this->authorizeParticipant($sender, $application);
        $data = $request->validate([
            'message' => ['required', 'string', 'max:5000'],
        ]);

        $message = DB::transaction(function () use ($sender, $application, $data): ProjectMessage {
            $message = $application->messages()->create([
                'sender_id' => $sender->id,
                'body' => $data['message'],
            ]);

            $recipient = $sender->id === $application->applicant_id
                ? $application->project->owner
                : $application->applicant;
            $recipient->notify(new ProjectNotification(
                'Nouveau message sur un projet',
                $sender->name.' vous a répondu au sujet de « '.$application->project->title.' ».',
                $application->project_id,
                $application->id,
            ));

            return $message;
        });

        return response()->json(['data' => ['message' => $message->load('sender:id,name')]], 201);
    }

    public function review(Request $request, ProjectApplication $application): JsonResponse
    {
        $user = $request->user();
        $project = $application->project;
        abort_unless(
            $user->role === 'admin' || ($user->capabilityRole() === 'manager' && $project->owner_id === $user->id),
            403,
        );
        abort_unless($application->type === 'application' && $application->status === 'pending', 422);

        $data = $request->validate([
            'decision' => ['required', Rule::in(['accepted', 'rejected'])],
        ]);

        $application->update(['status' => $data['decision']]);
        $application->applicant->notify(new ProjectNotification(
            'Réponse à votre candidature',
            $data['decision'] === 'accepted'
                ? 'Votre candidature au projet « '.$project->title.' » a été retenue.'
                : 'Votre candidature au projet « '.$project->title.' » n’a pas été retenue.',
            $project->id,
            $application->id,
        ));

        return response()->json(['data' => ['application' => $this->loadApplication($application->refresh())]]);
    }

    private function authorizeParticipant(User $user, ProjectApplication $application): void
    {
        abort_unless(
            $user->role === 'admin'
                || $user->id === $application->applicant_id
                || ($user->capabilityRole() === 'manager' && $user->id === $application->project->owner_id),
            404,
        );
    }

    private function loadApplication(ProjectApplication $application): ProjectApplication
    {
        return $application->load([
            'project:id,title,owner_id',
            'applicant:id,name,email,department',
            'applicant.organizationRole:id,slug,capability_role',
            'messages.sender:id,name',
            'messages.sender.organizationRole:id,slug,capability_role',
        ]);
    }
}
