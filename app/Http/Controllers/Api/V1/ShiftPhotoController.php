<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Shift;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Контроллер для скачивания фото смен.
 *
 * Контроль доступа:
 * - Owner/Manager: могут скачивать фото любой смены
 * - Employee: может скачивать только фото своих смен
 * - Observer: не имеет доступа
 */
class ShiftPhotoController extends Controller
{
    /**
     * Скачать фото смены.
     *
     * Доступ по подписанному URL с проверкой авторизации.
     *
     * @param Request $request HTTP-запрос
     * @param int $id ID смены
     * @param string $type Тип фото: 'opening' или 'closing'
     * @return BinaryFileResponse|JsonResponse
     */
    public function download(Request $request, int $id, string $type): BinaryFileResponse|JsonResponse
    {
        // Проверка подписи URL
        if (!$request->hasValidSignature()) {
            return response()->json([
                'message' => 'Ссылка недействительна или истекла'
            ], 403);
        }

        // Проверка типа фото
        if (!in_array($type, ['opening', 'closing'], true)) {
            return response()->json([
                'message' => 'Неверный тип фото'
            ], 400);
        }

        $shift = Shift::find($id);

        if (!$shift) {
            return response()->json([
                'message' => 'Смена не найдена'
            ], 404);
        }

        // Безопасность обеспечивается подписанным URL:
        // - URL генерируется только для авторизованных пользователей в ShiftResource
        // - URL имеет ограниченное время жизни (60 мин)
        // - Проверка прав происходит при генерации URL, а не при скачивании

        // Получаем путь к фото
        $photoPath = $type === 'opening'
            ? $shift->opening_photo_path
            : $shift->closing_photo_path;

        if (!$photoPath) {
            return response()->json([
                'message' => 'Фото не найдено'
            ], 404);
        }

        // Проверяем существование файла
        if (!Storage::disk('shift_photos')->exists($photoPath)) {
            return response()->json([
                'message' => 'Файл не найден на сервере'
            ], 404);
        }

        $fullPath = Storage::disk('shift_photos')->path($photoPath);
        $mimeType = mime_content_type($fullPath) ?: 'image/jpeg';
        $filename = basename($photoPath);

        // Используем response()->file() для inline отображения (не скачивания)
        return response()->file($fullPath, [
            'Content-Type' => $mimeType,
            'Content-Disposition' => 'inline; filename="' . $filename . '"',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    /**
     * Показать фото смены с авторизацией через Bearer token.
     *
     * Стабильный URL без подписи - авторизация через auth:sanctum middleware.
     * Поддерживает ETag для эффективного кеширования (304 Not Modified).
     *
     * @param Request $request HTTP-запрос
     * @param int $id ID смены
     * @param string $type Тип фото: 'opening' или 'closing'
     * @return BinaryFileResponse|JsonResponse|Response
     */
    public function show(Request $request, int $id, string $type): BinaryFileResponse|JsonResponse|Response
    {
        $shift = Shift::find($id);

        if (!$shift) {
            return response()->json([
                'message' => 'Смена не найдена'
            ], 404);
        }

        $user = $request->user();

        // Проверка доступа
        if (!$this->canViewPhoto($user, $shift)) {
            return response()->json([
                'message' => 'Доступ запрещён'
            ], 403);
        }

        // Получаем путь к фото
        $photoPath = $type === 'opening'
            ? $shift->opening_photo_path
            : $shift->closing_photo_path;

        if (!$photoPath) {
            return response()->json([
                'message' => 'Фото не найдено'
            ], 404);
        }

        // Проверяем существование файла
        if (!Storage::disk('shift_photos')->exists($photoPath)) {
            return response()->json([
                'message' => 'Файл не найден на сервере'
            ], 404);
        }

        $fullPath = Storage::disk('shift_photos')->path($photoPath);
        $lastModified = filemtime($fullPath);
        $etag = '"' . md5($photoPath . $lastModified) . '"';
        $mimeType = mime_content_type($fullPath) ?: 'image/jpeg';

        // Conditional request - 304 Not Modified если не изменилось
        $ifNoneMatch = $request->header('If-None-Match');
        if ($ifNoneMatch === $etag) {
            return response('', 304)->withHeaders([
                'ETag' => $etag,
                'Cache-Control' => 'private, max-age=300, must-revalidate',
            ]);
        }

        return response()->file($fullPath, [
            'Content-Type' => $mimeType,
            'Content-Disposition' => 'inline; filename="' . basename($photoPath) . '"',
            'Cache-Control' => 'private, max-age=300, must-revalidate',
            'ETag' => $etag,
        ]);
    }

    /**
     * Проверка доступа пользователя к фото смены.
     *
     * @param \App\Models\User $user
     * @param Shift $shift
     * @return bool
     */
    private function canViewPhoto($user, Shift $shift): bool
    {
        // Owner и Manager могут просматривать все фото
        if (in_array($user->role, [Role::OWNER, Role::MANAGER], true)) {
            return true;
        }

        // Employee может просматривать только свои фото
        if ($user->role === Role::EMPLOYEE) {
            return $shift->user_id === $user->id;
        }

        // Observer не имеет доступа к фото
        return false;
    }
}
