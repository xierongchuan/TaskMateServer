<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Exceptions\InvalidStatusTransitionException;
use App\Models\User;
use App\StateMachines\TaskStatusMachine;

/**
 * Юнит-тесты для TaskStatusMachine.
 *
 * Тесты не используют БД — пользователи создаются через make().
 */
describe('TaskStatusMachine', function () {
    beforeEach(function () {
        $this->machine = new TaskStatusMachine;

        $this->employee = User::make(['role' => Role::EMPLOYEE]);
        $this->manager = User::make(['role' => Role::MANAGER]);
        $this->owner = User::make(['role' => Role::OWNER]);
    });

    describe('canTransition — null (новый response)', function () {
        it('разрешает любой переход из null для сотрудника', function () {
            expect($this->machine->canTransition(null, 'pending', $this->employee))->toBeTrue();
            expect($this->machine->canTransition(null, 'acknowledged', $this->employee))->toBeTrue();
            expect($this->machine->canTransition(null, 'pending_review', $this->employee))->toBeTrue();
            expect($this->machine->canTransition(null, 'completed', $this->employee))->toBeTrue();
        });

        it('разрешает любой переход из null для менеджера', function () {
            expect($this->machine->canTransition(null, 'pending', $this->manager))->toBeTrue();
            expect($this->machine->canTransition(null, 'completed', $this->manager))->toBeTrue();
        });
    });

    describe('canTransition — pending', function () {
        it('разрешает pending -> acknowledged для сотрудника', function () {
            expect($this->machine->canTransition('pending', 'acknowledged', $this->employee))->toBeTrue();
        });

        it('разрешает pending -> pending_review для сотрудника', function () {
            expect($this->machine->canTransition('pending', 'pending_review', $this->employee))->toBeTrue();
        });

        it('разрешает pending -> completed для сотрудника', function () {
            expect($this->machine->canTransition('pending', 'completed', $this->employee))->toBeTrue();
        });

        it('запрещает pending -> rejected для сотрудника', function () {
            expect($this->machine->canTransition('pending', 'rejected', $this->employee))->toBeFalse();
        });

        it('запрещает pending -> pending для сотрудника', function () {
            expect($this->machine->canTransition('pending', 'pending', $this->employee))->toBeFalse();
        });

        it('разрешает pending -> pending для менеджера (сброс)', function () {
            expect($this->machine->canTransition('pending', 'pending', $this->manager))->toBeTrue();
        });
    });

    describe('canTransition — acknowledged', function () {
        it('разрешает acknowledged -> pending_review для сотрудника', function () {
            expect($this->machine->canTransition('acknowledged', 'pending_review', $this->employee))->toBeTrue();
        });

        it('разрешает acknowledged -> completed для сотрудника', function () {
            expect($this->machine->canTransition('acknowledged', 'completed', $this->employee))->toBeTrue();
        });

        it('запрещает acknowledged -> pending для сотрудника', function () {
            expect($this->machine->canTransition('acknowledged', 'pending', $this->employee))->toBeFalse();
        });

        it('разрешает acknowledged -> pending для менеджера', function () {
            expect($this->machine->canTransition('acknowledged', 'pending', $this->manager))->toBeTrue();
        });

        it('разрешает acknowledged -> pending для владельца', function () {
            expect($this->machine->canTransition('acknowledged', 'pending', $this->owner))->toBeTrue();
        });
    });

    describe('canTransition — pending_review', function () {
        it('запрещает pending_review -> acknowledged для сотрудника', function () {
            expect($this->machine->canTransition('pending_review', 'acknowledged', $this->employee))->toBeFalse();
        });

        it('запрещает pending_review -> completed для сотрудника', function () {
            expect($this->machine->canTransition('pending_review', 'completed', $this->employee))->toBeFalse();
        });

        it('запрещает pending_review -> pending для сотрудника', function () {
            expect($this->machine->canTransition('pending_review', 'pending', $this->employee))->toBeFalse();
        });

        it('разрешает pending_review -> pending для менеджера', function () {
            expect($this->machine->canTransition('pending_review', 'pending', $this->manager))->toBeTrue();
        });

        it('разрешает pending_review -> completed для менеджера (без верификации)', function () {
            expect($this->machine->canTransition('pending_review', 'completed', $this->manager))->toBeTrue();
        });
    });

    describe('canTransition — rejected', function () {
        it('разрешает rejected -> pending_review для сотрудника (переотправка)', function () {
            expect($this->machine->canTransition('rejected', 'pending_review', $this->employee))->toBeTrue();
        });

        it('разрешает rejected -> completed для сотрудника', function () {
            expect($this->machine->canTransition('rejected', 'completed', $this->employee))->toBeTrue();
        });

        it('запрещает rejected -> acknowledged для сотрудника', function () {
            expect($this->machine->canTransition('rejected', 'acknowledged', $this->employee))->toBeFalse();
        });

        it('разрешает rejected -> pending для менеджера', function () {
            expect($this->machine->canTransition('rejected', 'pending', $this->manager))->toBeTrue();
        });
    });

    describe('canTransition — completed (финальный)', function () {
        it('запрещает completed -> pending_review для сотрудника', function () {
            expect($this->machine->canTransition('completed', 'pending_review', $this->employee))->toBeFalse();
        });

        it('запрещает completed -> acknowledged для сотрудника', function () {
            expect($this->machine->canTransition('completed', 'acknowledged', $this->employee))->toBeFalse();
        });

        it('разрешает completed -> pending для менеджера (сброс финального статуса)', function () {
            expect($this->machine->canTransition('completed', 'pending', $this->manager))->toBeTrue();
        });

        it('разрешает completed -> pending для владельца', function () {
            expect($this->machine->canTransition('completed', 'pending', $this->owner))->toBeTrue();
        });
    });

    describe('validateTransition', function () {
        it('не бросает исключение при допустимом переходе', function () {
            expect(fn () => $this->machine->validateTransition('pending', 'acknowledged', $this->employee))
                ->not->toThrow(InvalidStatusTransitionException::class);
        });

        it('бросает InvalidStatusTransitionException при недопустимом переходе', function () {
            expect(fn () => $this->machine->validateTransition('completed', 'acknowledged', $this->employee))
                ->toThrow(InvalidStatusTransitionException::class);
        });

        it('сообщение исключения содержит from и to статусы', function () {
            try {
                $this->machine->validateTransition('completed', 'acknowledged', $this->employee);
                $this->fail('Ожидалось исключение');
            } catch (InvalidStatusTransitionException $e) {
                expect($e->getMessage())->toContain('completed');
                expect($e->getMessage())->toContain('acknowledged');
                expect($e->getFromStatus())->toBe('completed');
                expect($e->getToStatus())->toBe('acknowledged');
                expect($e->getHttpCode())->toBe(422);
            }
        });

        it('сообщение исключения при null from содержит null', function () {
            // null из validateTransition не достигается — null всегда допустим.
            // Тестируем напрямую через конструктор исключения.
            $e = new InvalidStatusTransitionException(null, 'unknown');
            expect($e->getMessage())->toContain('null');
            expect($e->getFromStatus())->toBeNull();
        });

        it('не бросает исключение при переходе из null (новый response)', function () {
            expect(fn () => $this->machine->validateTransition(null, 'pending_review', $this->employee))
                ->not->toThrow(InvalidStatusTransitionException::class);
        });
    });

    describe('getAllowedTransitions', function () {
        it('возвращает все статусы для null (нет существующего response)', function () {
            $allowed = $this->machine->getAllowedTransitions(null, $this->employee);
            expect($allowed)->toContain('pending');
            expect($allowed)->toContain('acknowledged');
            expect($allowed)->toContain('pending_review');
            expect($allowed)->toContain('completed');
        });

        it('возвращает базовые переходы из pending для сотрудника', function () {
            $allowed = $this->machine->getAllowedTransitions('pending', $this->employee);
            expect($allowed)->toContain('acknowledged');
            expect($allowed)->toContain('pending_review');
            expect($allowed)->toContain('completed');
            expect($allowed)->not->toContain('pending');
            expect($allowed)->not->toContain('rejected');
        });

        it('включает pending в переходы из pending_review для менеджера', function () {
            $allowed = $this->machine->getAllowedTransitions('pending_review', $this->manager);
            expect($allowed)->toContain('pending');
            expect($allowed)->toContain('completed');
        });

        it('возвращает пустой массив из completed для сотрудника', function () {
            $allowed = $this->machine->getAllowedTransitions('completed', $this->employee);
            expect($allowed)->toBe([]);
        });

        it('возвращает [pending] из completed для менеджера', function () {
            $allowed = $this->machine->getAllowedTransitions('completed', $this->manager);
            expect($allowed)->toContain('pending');
        });

        it('возвращает пустой массив для неизвестного статуса у сотрудника', function () {
            $allowed = $this->machine->getAllowedTransitions('unknown_status', $this->employee);
            expect($allowed)->toBe([]);
        });
    });
});
