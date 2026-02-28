<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Events\DelegationAccepted;
use App\Events\DelegationRejected;
use App\Events\DelegationRequested;
use App\Events\TaskApproved;
use App\Events\TaskAssigned;
use App\Events\TaskPendingReview;
use App\Events\TaskRejected;
use App\Events\TaskRejectedBulk;
use App\Models\AutoDealership;
use App\Models\NotificationSetting;
use App\Models\Task;
use App\Models\TaskDelegation;
use App\Models\TaskResponse;
use App\Models\User;

describe('Task Events', function () {
    beforeEach(function () {
        $this->dealership = AutoDealership::factory()->create();
        $this->manager = User::factory()->create([
            'role' => Role::MANAGER->value,
            'dealership_id' => $this->dealership->id,
        ]);
        $this->employee = User::factory()->create([
            'role' => Role::EMPLOYEE->value,
            'dealership_id' => $this->dealership->id,
        ]);
    });

    describe('TaskAssigned', function () {
        it('возвращает null если канал отключён', function () {
            $task = Task::factory()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
            ]);

            $event = new TaskAssigned($task, [$this->employee->id]);

            expect($event->rabbitPayload())->toBeNull();
        });

        it('возвращает null если после фильтрации по ролям список пуст', function () {
            NotificationSetting::create([
                'dealership_id' => $this->dealership->id,
                'channel_type' => NotificationSetting::CHANNEL_TASK_ASSIGNED,
                'is_enabled' => true,
                'recipient_roles' => ['manager'],
            ]);

            $task = Task::factory()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
            ]);

            $event = new TaskAssigned($task, [$this->employee->id]);

            expect($event->rabbitPayload())->toBeNull();
        });

        it('возвращает payload при включённом канале', function () {
            NotificationSetting::create([
                'dealership_id' => $this->dealership->id,
                'channel_type' => NotificationSetting::CHANNEL_TASK_ASSIGNED,
                'is_enabled' => true,
                'recipient_roles' => ['employee'],
            ]);

            $task = Task::factory()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
            ]);

            $event = new TaskAssigned($task, [$this->employee->id]);
            $payload = $event->rabbitPayload();

            expect($payload)->not->toBeNull()
                ->and($payload['event'])->toBe('task.assigned')
                ->and($payload['task']['id'])->toBe($task->id)
                ->and($payload['user_ids'])->toBe([$this->employee->id])
                ->and($payload)->toHaveKey('timestamp');
        });
    });

    describe('TaskApproved', function () {
        it('всегда возвращает payload (критическое уведомление)', function () {
            $task = Task::factory()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
            ]);
            $response = TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employee->id,
                'status' => 'completed',
                'responded_at' => now(),
            ]);

            $event = new TaskApproved($response);
            $payload = $event->rabbitPayload();

            expect($payload)->not->toBeNull()
                ->and($payload['event'])->toBe('task.approved')
                ->and($payload['task']['id'])->toBe($task->id)
                ->and($payload['user_ids'])->toBe([$this->employee->id]);
        });
    });

    describe('TaskRejected', function () {
        it('всегда возвращает payload с причиной', function () {
            $task = Task::factory()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
            ]);
            $response = TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employee->id,
                'status' => 'rejected',
                'responded_at' => now(),
            ]);

            $event = new TaskRejected($response, 'Некачественное фото');
            $payload = $event->rabbitPayload();

            expect($payload)->not->toBeNull()
                ->and($payload['event'])->toBe('task.rejected')
                ->and($payload['reason'])->toBe('Некачественное фото')
                ->and($payload['user_ids'])->toBe([$this->employee->id]);
        });
    });

    describe('TaskPendingReview', function () {
        it('отправляет менеджерам и владельцам, исключая автора ответа', function () {
            $task = Task::factory()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
            ]);
            $response = TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employee->id,
                'status' => 'pending_review',
                'responded_at' => now(),
            ]);

            $event = new TaskPendingReview($response);
            $payload = $event->rabbitPayload();

            expect($payload)->not->toBeNull()
                ->and($payload['event'])->toBe('task.pending_review')
                ->and($payload['user_ids'])->toContain($this->manager->id)
                ->and($payload['user_ids'])->not->toContain($this->employee->id)
                ->and($payload)->toHaveKey('submitted_by')
                ->and($payload)->toHaveKey('response_id');
        });

        it('возвращает null если нет менеджеров в автосалоне', function () {
            $dealership2 = AutoDealership::factory()->create();
            $employee2 = User::factory()->create([
                'role' => Role::EMPLOYEE->value,
                'dealership_id' => $dealership2->id,
            ]);

            $task = Task::factory()->create([
                'dealership_id' => $dealership2->id,
                'creator_id' => $employee2->id,
            ]);
            $response = TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $employee2->id,
                'status' => 'pending_review',
                'responded_at' => now(),
            ]);

            $event = new TaskPendingReview($response);

            expect($event->rabbitPayload())->toBeNull();
        });
    });

    describe('TaskRejectedBulk', function () {
        it('возвращает payload для всех пользователей', function () {
            $task = Task::factory()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
            ]);

            $event = new TaskRejectedBulk($task, [$this->employee->id], 'Массовое отклонение');
            $payload = $event->rabbitPayload();

            expect($payload)->not->toBeNull()
                ->and($payload['event'])->toBe('task.rejected')
                ->and($payload['user_ids'])->toBe([$this->employee->id])
                ->and($payload['reason'])->toBe('Массовое отклонение');
        });
    });

    describe('DelegationRequested', function () {
        it('возвращает payload для целевого сотрудника', function () {
            $task = Task::factory()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
            ]);
            $delegation = TaskDelegation::create([
                'task_id' => $task->id,
                'from_user_id' => $this->employee->id,
                'to_user_id' => $this->manager->id,
                'reason' => 'Не могу выполнить',
                'status' => 'pending',
            ]);

            $event = new DelegationRequested($delegation);
            $payload = $event->rabbitPayload();

            expect($payload)->not->toBeNull()
                ->and($payload['event'])->toBe('task.delegation_requested')
                ->and($payload['user_ids'])->toBe([$this->manager->id])
                ->and($payload['delegation_id'])->toBe($delegation->id)
                ->and($payload)->toHaveKey('from_user')
                ->and($payload)->toHaveKey('reason');
        });
    });

    describe('DelegationAccepted', function () {
        it('возвращает payload для инициатора и менеджеров', function () {
            $task = Task::factory()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
            ]);

            $employee2 = User::factory()->create([
                'role' => Role::EMPLOYEE->value,
                'dealership_id' => $this->dealership->id,
            ]);

            $delegation = TaskDelegation::create([
                'task_id' => $task->id,
                'from_user_id' => $this->employee->id,
                'to_user_id' => $employee2->id,
                'reason' => 'Не могу выполнить',
                'status' => 'accepted',
            ]);

            $event = new DelegationAccepted($delegation);
            $payload = $event->rabbitPayload();

            expect($payload)->not->toBeNull()
                ->and($payload['event'])->toBe('task.delegation_accepted')
                ->and($payload['user_ids'])->toContain($this->employee->id)
                ->and($payload['user_ids'])->toContain($this->manager->id)
                ->and($payload['delegation_id'])->toBe($delegation->id);
        });
    });

    describe('DelegationRejected', function () {
        it('возвращает payload для инициатора', function () {
            $task = Task::factory()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
            ]);
            $delegation = TaskDelegation::create([
                'task_id' => $task->id,
                'from_user_id' => $this->employee->id,
                'to_user_id' => $this->manager->id,
                'reason' => 'Не могу выполнить',
                'rejection_reason' => 'Занят другой задачей',
                'status' => 'rejected',
            ]);

            $event = new DelegationRejected($delegation);
            $payload = $event->rabbitPayload();

            expect($payload)->not->toBeNull()
                ->and($payload['event'])->toBe('task.delegation_rejected')
                ->and($payload['user_ids'])->toBe([$this->employee->id])
                ->and($payload['reason'])->toBe('Занят другой задачей')
                ->and($payload['delegation_id'])->toBe($delegation->id);
        });
    });
});
