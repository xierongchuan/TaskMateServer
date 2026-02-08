<?php

declare(strict_types=1);

use App\Exceptions\AccessDeniedException;
use App\Exceptions\DuplicateTaskException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');

        $middleware->alias([
            'role' => \App\Http\Middleware\CheckRole::class,
        ]);

        // Глобальный middleware для проверки режима обслуживания
        $middleware->api(append: [
            \App\Http\Middleware\CheckMaintenanceMode::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (DuplicateTaskException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error_type' => 'duplicate_task'
            ], 422);
        });

        $exceptions->render(function (AccessDeniedException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error_type' => 'access_denied'
            ], 403);
        });

        // Обработчик ошибок базы данных (SQL-запросы)
        $exceptions->render(function (QueryException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                Log::error('Database QueryException', [
                    'message' => $e->getMessage(),
                    'sql' => $e->getSql(),
                    'bindings' => $e->getBindings(),
                ]);

                $response = [
                    'success' => false,
                    'message' => 'Ошибка базы данных. Возможно, требуется выполнить миграции.',
                    'error_type' => 'database_error',
                ];

                // В режиме отладки добавляем детали (только для разработки)
                if (config('app.debug')) {
                    $response['debug'] = [
                        'exception' => class_basename($e),
                        'message' => $e->getMessage(),
                    ];
                }

                return response()->json($response, 500);
            }
        });

        // Обработчик ошибок подключения к БД (PDO)
        $exceptions->render(function (\PDOException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                Log::error('Database PDOException', [
                    'message' => $e->getMessage(),
                ]);

                $response = [
                    'success' => false,
                    'message' => 'Не удалось подключиться к базе данных.',
                    'error_type' => 'database_connection_error',
                ];

                if (config('app.debug')) {
                    $response['debug'] = [
                        'exception' => class_basename($e),
                        'message' => $e->getMessage(),
                    ];
                }

                return response()->json($response, 500);
            }
        });

        // Общий обработчик для всех необработанных исключений API
        $exceptions->render(function (\Throwable $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                // Пропускаем исключения, которые Laravel должен обработать стандартным способом
                if ($e instanceof QueryException ||
                    $e instanceof \PDOException ||
                    $e instanceof DuplicateTaskException ||
                    $e instanceof AccessDeniedException ||
                    $e instanceof \Illuminate\Validation\ValidationException ||
                    $e instanceof \Illuminate\Auth\AuthenticationException ||
                    $e instanceof \Illuminate\Auth\Access\AuthorizationException ||
                    $e instanceof \Symfony\Component\HttpKernel\Exception\HttpException ||
                    $e instanceof \Illuminate\Http\Exceptions\HttpResponseException) {
                    return null;
                }

                Log::error('Unhandled API Exception', [
                    'exception' => get_class($e),
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]);

                $response = [
                    'success' => false,
                    'message' => 'Внутренняя ошибка сервера.',
                    'error_type' => 'server_error',
                ];

                if (config('app.debug')) {
                    $response['debug'] = [
                        'exception' => class_basename($e),
                        'message' => $e->getMessage(),
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                    ];
                }

                return response()->json($response, 500);
            }
        });
    })->create();
