<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\ArchivedTaskController;
use App\Http\Controllers\Api\V1\TaskController;
use App\Http\Controllers\Api\V1\TaskDelegationController;
use App\Http\Controllers\Api\V1\TaskGeneratorController;
use App\Http\Controllers\Api\V1\TaskProofController;
use App\Http\Controllers\Api\V1\TaskVerificationController;
use Illuminate\Support\Facades\Route;

// Tasks - READ операции
Route::get('/tasks', [TaskController::class, 'index']);
Route::get('/tasks/my-history', [TaskController::class, 'myHistory']);
Route::get('/tasks/{id}', [TaskController::class, 'show']);

// Tasks - WRITE операции (только managers и owners)
Route::post('/tasks', [TaskController::class, 'store'])
    ->middleware('role:manager,owner');
Route::put('/tasks/{id}', [TaskController::class, 'update'])
    ->middleware('role:manager,owner');
Route::delete('/tasks/{id}', [TaskController::class, 'destroy'])
    ->middleware('role:manager,owner');

// Task status update - доступно всем (сотрудники могут загружать доказательства)
Route::patch('/tasks/{id}/status', [TaskController::class, 'updateStatus']);

// Task Proofs - доказательства выполнения
// (download вынесен за пределы auth:sanctum - доступ по подписанному URL)
Route::get('/task-proofs/{id}', [TaskProofController::class, 'show']);
Route::delete('/task-proofs/{id}', [TaskProofController::class, 'destroy']);
Route::delete('/task-shared-proofs/{id}', [TaskProofController::class, 'destroyShared']);

// Task Verification - верификация доказательств (только managers и owners)
Route::post('/task-responses/{id}/approve', [TaskVerificationController::class, 'approve'])
    ->middleware('role:manager,owner');
Route::post('/task-responses/{id}/reject', [TaskVerificationController::class, 'reject'])
    ->middleware('role:manager,owner');
Route::post('/tasks/{id}/reject-all-responses', [TaskVerificationController::class, 'rejectAll'])
    ->middleware('role:manager,owner');

// Task Delegations - делегирование задач между сотрудниками
Route::post('/tasks/{task}/delegations', [TaskDelegationController::class, 'store']);
Route::get('/task-delegations', [TaskDelegationController::class, 'index']);
Route::get('/task-delegations/{id}', [TaskDelegationController::class, 'show']);
Route::post('/task-delegations/{id}/accept', [TaskDelegationController::class, 'accept']);
Route::post('/task-delegations/{id}/reject', [TaskDelegationController::class, 'reject']);
Route::post('/task-delegations/{id}/cancel', [TaskDelegationController::class, 'cancel']);

// Task Generators - READ операции
Route::get('/task-generators', [TaskGeneratorController::class, 'index']);
Route::get('/task-generators/{id}', [TaskGeneratorController::class, 'show']);
Route::get('/task-generators/{id}/tasks', [TaskGeneratorController::class, 'generatedTasks']);
Route::get('/task-generators/{id}/stats', [TaskGeneratorController::class, 'statistics']);

// Task Generators - WRITE операции (только managers и owners)
Route::post('/task-generators', [TaskGeneratorController::class, 'store'])
    ->middleware('role:manager,owner');
Route::put('/task-generators/{id}', [TaskGeneratorController::class, 'update'])
    ->middleware('role:manager,owner');
Route::delete('/task-generators/{id}', [TaskGeneratorController::class, 'destroy'])
    ->middleware('role:manager,owner');
Route::post('/task-generators/{id}/pause', [TaskGeneratorController::class, 'pause'])
    ->middleware('role:manager,owner');
Route::post('/task-generators/{id}/resume', [TaskGeneratorController::class, 'resume'])
    ->middleware('role:manager,owner');
Route::post('/task-generators/pause-all', [TaskGeneratorController::class, 'pauseAll'])
    ->middleware('role:owner');
Route::post('/task-generators/resume-all', [TaskGeneratorController::class, 'resumeAll'])
    ->middleware('role:owner');

// Archived Tasks
Route::get('/archived-tasks', [ArchivedTaskController::class, 'index']);
Route::get('/archived-tasks/statistics', [ArchivedTaskController::class, 'statistics']);
Route::get('/archived-tasks/export', [ArchivedTaskController::class, 'export']);
Route::post('/archived-tasks/{id}/restore', [ArchivedTaskController::class, 'restore'])
    ->middleware('role:manager,owner');
