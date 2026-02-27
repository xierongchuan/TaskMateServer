<?php

declare(strict_types=1);

namespace App\Traits;

use Illuminate\Http\JsonResponse;

/**
 * Trait для стандартизации API ответов.
 *
 * Обеспечивает единообразный формат ответов для всех API контроллеров.
 */
trait ApiResponses
{
    /**
     * Успешный ответ с данными.
     *
     * @param mixed $data Данные для возврата
     * @param string|null $message Опциональное сообщение
     * @param int $code HTTP статус код
     */
    protected function successResponse(mixed $data = null, ?string $message = null, int $code = 200): JsonResponse
    {
        $response = ['success' => true];

        if ($message !== null) {
            $response['message'] = $message;
        }

        if ($data !== null) {
            $response['data'] = $data;
        }

        return response()->json($response, $code);
    }

    /**
     * Ответ об ошибке.
     *
     * @param string $message Сообщение об ошибке
     * @param int $code HTTP статус код
     * @param array|null $errors Детали ошибок (для валидации)
     */
    protected function errorResponse(string $message, int $code = 400, ?array $errors = null): JsonResponse
    {
        $response = [
            'success' => false,
            'message' => $message,
        ];

        if ($errors !== null) {
            $response['errors'] = $errors;
        }

        return response()->json($response, $code);
    }

    /**
     * Ресурс не найден.
     *
     * @param string $resource Название ресурса на русском
     */
    protected function notFoundResponse(string $resource = 'Ресурс'): JsonResponse
    {
        return $this->errorResponse("{$resource} не найден", 404);
    }

    /**
     * Ошибка доступа.
     *
     * @param string $message Сообщение об ошибке доступа
     */
    protected function forbiddenResponse(string $message = 'Доступ запрещён'): JsonResponse
    {
        return $this->errorResponse($message, 403);
    }

    /**
     * Ошибка валидации.
     *
     * @param string $message Общее сообщение
     * @param array $errors Массив ошибок валидации
     */
    protected function validationErrorResponse(string $message, array $errors): JsonResponse
    {
        return $this->errorResponse($message, 422, $errors);
    }

    /**
     * Внутренняя ошибка сервера.
     *
     * @param string $message Сообщение для пользователя
     * @param \Throwable|null $e Исключение (для debug режима)
     */
    protected function serverErrorResponse(string $message = 'Внутренняя ошибка сервера', ?\Throwable $e = null): JsonResponse
    {
        $response = [
            'success' => false,
            'message' => $message,
        ];

        if (config('app.debug') && $e !== null) {
            $response['error'] = $e->getMessage();
            $response['trace'] = $e->getTraceAsString();
        }

        return response()->json($response, 500);
    }

    /**
     * Успешное создание ресурса.
     *
     * @param mixed $data Созданный ресурс
     * @param string|null $message Сообщение об успехе
     */
    protected function createdResponse(mixed $data, ?string $message = null): JsonResponse
    {
        return $this->successResponse($data, $message, 201);
    }

    /**
     * Ресурс успешно удалён.
     *
     * @param string|null $message Сообщение об успехе
     */
    protected function deletedResponse(?string $message = 'Успешно удалено'): JsonResponse
    {
        return $this->successResponse(null, $message);
    }

    /**
     * Слишком много запросов (rate limiting).
     *
     * @param string $message Сообщение
     * @param int $retryAfter Секунды до следующей попытки
     */
    protected function tooManyRequestsResponse(string $message, int $retryAfter = 60): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'retry_after' => $retryAfter,
        ], 429);
    }
}
