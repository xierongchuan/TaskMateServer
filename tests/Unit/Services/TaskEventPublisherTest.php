<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\AutoDealership;
use App\Models\NotificationSetting;
use App\Models\Task;
use App\Models\TaskResponse;
use App\Models\User;
use App\Services\TaskEventPublisher;
use Illuminate\Support\Facades\Log;

describe('TaskEventPublisher', function () {
    beforeEach(function () {
        // Переключаем RabbitMQ на несуществующий хост, чтобы publish() всегда падал
        config()->set('queue.connections.rabbitmq.hosts.0.host', 'invalid-host-for-test');
        config()->set('queue.connections.rabbitmq.hosts.0.port', 59999);

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

    afterEach(function () {
        // Сброс статического соединения между тестами
        $ref = new ReflectionClass(TaskEventPublisher::class);
        $prop = $ref->getProperty('connection');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    });

    describe('publishTaskAssigned', function () {
        it('не публикует если канал отключён', function () {
            // Arrange — канал не создан (по умолчанию disabled)
            $task = Task::factory()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
            ]);

            Log::spy();

            // Act
            TaskEventPublisher::publishTaskAssigned($task, [$this->employee->id]);

            // Assert — не дошло до publish, поэтому Log::warning не вызван
            Log::shouldNotHaveReceived('warning');
        });

        it('не публикует если после фильтрации по ролям список пуст', function () {
            // Arrange — канал включён, но recipient_roles = ['manager']
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

            Log::spy();

            // Act — передаём только employee, а фильтр требует manager
            TaskEventPublisher::publishTaskAssigned($task, [$this->employee->id]);

            // Assert — filteredUserIds пуст, early return
            Log::shouldNotHaveReceived('warning');
        });

        it('пытается опубликовать при включённом канале', function () {
            // Arrange
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

            Log::spy();

            // Act
            TaskEventPublisher::publishTaskAssigned($task, [$this->employee->id]);

            // Assert — доходит до publish(), но AMQP недоступен → Log::warning
            Log::shouldHaveReceived('warning')->once();
        });
    });

    describe('publishTaskApproved', function () {
        it('всегда пытается опубликовать (критическое уведомление)', function () {
            // Arrange — без NotificationSetting
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

            Log::spy();

            // Act
            TaskEventPublisher::publishTaskApproved($response);

            // Assert
            Log::shouldHaveReceived('warning')->once();
        });
    });

    describe('publishTaskRejected', function () {
        it('всегда пытается опубликовать с причиной', function () {
            // Arrange
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

            Log::spy();

            // Act
            TaskEventPublisher::publishTaskRejected($response, 'Некачественное фото');

            // Assert
            Log::shouldHaveReceived('warning')->once();
        });
    });

    describe('publishTaskPendingReview', function () {
        it('отправляет менеджерам и владельцам, исключая автора ответа', function () {
            // Arrange
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

            Log::spy();

            // Act
            TaskEventPublisher::publishTaskPendingReview($response);

            // Assert — manager есть в dealership, поэтому дойдёт до publish
            Log::shouldHaveReceived('warning')->once();
        });

        it('не публикует если нет менеджеров в автосалоне', function () {
            // Arrange — создаём dealership без менеджеров
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

            Log::spy();

            // Act
            TaskEventPublisher::publishTaskPendingReview($response);

            // Assert — нет менеджеров → early return
            Log::shouldNotHaveReceived('warning');
        });
    });

    describe('publishTaskRejectedBulk', function () {
        it('пытается опубликовать для всех пользователей', function () {
            // Arrange
            $task = Task::factory()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
            ]);

            Log::spy();

            // Act
            TaskEventPublisher::publishTaskRejectedBulk(
                $task,
                [$this->employee->id],
                'Массовое отклонение'
            );

            // Assert
            Log::shouldHaveReceived('warning')->once();
        });
    });
});
