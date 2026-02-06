# Проверка работы планировщика Laravel в Docker

## Запуск контейнера с планировщиком

```bash
# Перейти в корневую директорию проекта
cd /home/temur/LaravelProjects/TaskMate

# Запустить все сервисы включая планировщик
podman compose up -d

# Проверить статус контейнера планировщика
docker ps | grep scheduler
```

## Проверка логов

```bash
# Посмотреть логи планировщика
docker logs svc-scheduler

# Посмотреть логи supervisor в реальном времени
docker exec svc-scheduler tail -f /var/log/supervisor/laravel-scheduler.log

# Посмотреть логи воркеров очередей
docker exec svc-scheduler tail -f /var/log/supervisor/laravel-worker.log
```

## Проверка статуса процессов

```bash
# Зайти в контейнер планировщика
docker exec -it svc-scheduler bash

# Проверить статус supervisor
supervisorctl status

# Должны увидеть примерно такое:
# laravel-scheduler              RUNNING   pid 10, uptime 0:05:12
# laravel-worker:laravel-worker_00   RUNNING   pid 11, uptime 0:05:12
# laravel-worker:laravel-worker_01   RUNNING   pid 12, uptime 0:05:12
```

## Тестирование планировщика

```bash
# Внутри контейнера выполнить ручной запуск планировщика
docker exec svc-scheduler php artisan schedule:run

# Проверить очереди
docker exec svc-scheduler php artisan queue:failed

# Очистить очередь при необходимости
docker exec svc-scheduler php artisan queue:clear
```

## Что делает планировщик

Планировщик запускает следующие задачи:

1. **ProcessRecurringTasksJob** - ежечасно (обработка повторяющихся задач)
2. **SendScheduledTasksJob** - каждые 15 минут (отправка запланированных задач)
3. **CheckOverdueTasksJob** - каждые 30 минут (проверка просроченных задач)
4. **ArchiveOldTasksJob** - ежедневно в 02:00 (архивация старых задач)
5. **SendDailySummaryJob** - ежедневно в 20:00 (отправка дневного отчета)
6. **SendWeeklyReportJob** - еженедельно по понедельникам в 09:00 (отправка недельного отчета)

## Диагностика проблем

Если планировщик не работает:

1. Проверить статус контейнера: `docker ps | grep scheduler`
2. Проверить логи: `docker logs svc-scheduler`
3. Проверить статус supervisor: `docker exec svc-scheduler supervisorctl status`
4. Проверить подключение к Redis: `docker exec svc-scheduler php artisan tinker --execute="Redis::ping()"`
