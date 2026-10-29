<?php

use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DivisionController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\OrganizationRoleController;
use App\Http\Controllers\Api\PrivacyPolicyController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\ProjectApplicationController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function (): void {
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:5,1');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
    });
});

Route::get('/public/divisions', [DivisionController::class, 'options']);
Route::get('/public/privacy-policy', [PrivacyPolicyController::class, 'publicShow']);

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/privacy-policy', [PrivacyPolicyController::class, 'show']);
    Route::put('/privacy-policy', [PrivacyPolicyController::class, 'update']);
    Route::patch('/profile', [ProfileController::class, 'update'])->middleware('page:profile');
    Route::get('/dashboard', DashboardController::class)->middleware('page:dashboard');
    Route::get('/projects', [ProjectController::class, 'index']);
    Route::post('/projects', [ProjectController::class, 'store']);
    Route::get('/projects/{project}', [ProjectController::class, 'show']);
    Route::patch('/projects/{project}', [ProjectController::class, 'update']);
    Route::post('/projects/{project}/applications', [ProjectApplicationController::class, 'store']);
    Route::get('/project-applications/{application}', [ProjectApplicationController::class, 'show']);
    Route::post('/project-applications/{application}/messages', [ProjectApplicationController::class, 'sendMessage']);
    Route::post('/project-applications/{application}/review', [ProjectApplicationController::class, 'review']);

    Route::get('/users', [UserController::class, 'index']);
    Route::get('/organization-roles', [OrganizationRoleController::class, 'index']);
    Route::post('/users', [UserController::class, 'store']);
    Route::get('/users/{user}', [UserController::class, 'show']);
    Route::get('/team', [UserController::class, 'team'])->middleware('page:team');
    Route::get('/managers', [UserController::class, 'managers']);
    Route::patch('/users/{user}', [UserController::class, 'update']);
    Route::get('/divisions', [DivisionController::class, 'index']);
    Route::post('/divisions', [DivisionController::class, 'store']);
    Route::patch('/divisions/{division}', [DivisionController::class, 'update']);
    Route::delete('/divisions/{division}', [DivisionController::class, 'destroy']);
    Route::post('/organization-roles', [OrganizationRoleController::class, 'store']);

    Route::middleware('page:tasks')->group(function (): void {
        Route::get('/agents', [TaskController::class, 'agents']);
        Route::get('/tasks/export', [TaskController::class, 'export']);
        Route::get('/tasks', [TaskController::class, 'index']);
        Route::post('/tasks', [TaskController::class, 'store']);
        Route::get('/tasks/{task}', [TaskController::class, 'show']);
        Route::patch('/tasks/{task}', [TaskController::class, 'update']);
        Route::delete('/tasks/{task}', [TaskController::class, 'destroy']);
        Route::get('/tasks/{task}/reassignment-options', [TaskController::class, 'reassignmentOptions']);
        Route::post('/tasks/{task}/reassign', [TaskController::class, 'reassign']);
        Route::post('/tasks/{task}/activities', [TaskController::class, 'addActivity']);
        Route::post('/tasks/{task}/start', [TaskController::class, 'start']);
        Route::get('/tasks/{task}/activities/{activity}/attachment', [TaskController::class, 'downloadAttachment'])
            ->name('tasks.activities.attachment');
        Route::post('/tasks/{task}/extension-request', [TaskController::class, 'requestExtension']);
        Route::post('/tasks/{task}/extension-decision', [TaskController::class, 'decideExtension']);
        Route::post('/tasks/{task}/submit', [TaskController::class, 'submit']);
        Route::post('/tasks/{task}/review', [TaskController::class, 'review']);
        Route::post('/tasks/{task}/admin-rejection', [TaskController::class, 'rejectByAdmin']);
    });

    Route::get('/audit-logs', [AuditLogController::class, 'index']);
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::patch('/notifications/{notification}/read', [NotificationController::class, 'markRead']);
});
