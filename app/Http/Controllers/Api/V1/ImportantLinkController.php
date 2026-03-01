<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreImportantLinkRequest;
use App\Http\Requests\Api\V1\UpdateImportantLinkRequest;
use App\Http\Resources\ImportantLinkResource;
use App\Models\ImportantLink;
use App\Traits\HasDealershipAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ImportantLinkController extends Controller
{
    use HasDealershipAccess;

    public function index(Request $request)
    {
        /** @var \App\Models\User $currentUser */
        $currentUser = $request->user();
        $perPage = (int) $request->query('per_page', '15');
        $dealershipId = $this->parseDealershipId($request);
        $isActive = $request->query('is_active');
        $search = $request->query('search');

        $query = ImportantLink::with(['creator', 'dealership']);

        // Проверка доступа к конкретному дилерству, если указан
        if ($dealershipId !== null) {
            if ($accessError = $this->validateDealershipAccess($currentUser, $dealershipId)) {
                return $accessError;
            }
            $query->where('dealership_id', $dealershipId);
        } else {
            // Ограничиваем выборку доступными дилерствами
            $this->scopeByAccessibleDealerships($query, $currentUser);
        }

        if ($isActive !== null) {
            $query->where('is_active', (bool) $isActive);
        }

        // Поиск по title, url и description
        if ($search !== null && $search !== '') {
            $escapedSearch = str_replace(['%', '_'], ['\%', '\_'], $search);
            $query->where(function ($q) use ($escapedSearch) {
                $q->where('title', 'ilike', "%{$escapedSearch}%")
                    ->orWhere('url', 'ilike', "%{$escapedSearch}%")
                    ->orWhere('description', 'ilike', "%{$escapedSearch}%");
            });
        }

        $links = $query->orderBy('sort_order')
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        return response()->json($links);
    }

    public function show(Request $request, $id)
    {
        $link = ImportantLink::with(['creator', 'dealership'])->findOrFail($id);

        // Проверка доступа к дилерству ссылки via Policy
        $this->authorize('view', $link);

        return response()->json(ImportantLinkResource::make($link)->resolve());
    }

    public function store(StoreImportantLinkRequest $request)
    {
        /** @var \App\Models\User $currentUser */
        $currentUser = $request->user();

        $validated = $request->validated();
        Log::info('ImportantLink store request', ['title' => $validated['title'] ?? null]);

        // Проверка доступа к дилерству, если указан
        if (! empty($validated['dealership_id'])) {
            if ($accessError = $this->validateDealershipAccess($currentUser, (int) $validated['dealership_id'])) {
                return $accessError;
            }
        }

        // Устанавливаем creator_id из текущего пользователя
        $validated['creator_id'] = $currentUser->id;

        $link = ImportantLink::create($validated);

        // Загружаем связи для ответа
        $link->load(['creator', 'dealership']);

        return response()->json(ImportantLinkResource::make($link)->resolve(), 201);
    }

    public function update(UpdateImportantLinkRequest $request, $id)
    {
        /** @var \App\Models\User $currentUser */
        $currentUser = $request->user();
        $link = ImportantLink::findOrFail($id);

        // Проверка доступа к текущему дилерству ссылки via Policy
        $this->authorize('update', $link);

        $validated = $request->validated();

        // Проверка доступа к новому дилерству, если меняется
        if (isset($validated['dealership_id']) && $validated['dealership_id'] !== $link->dealership_id) {
            if ($accessError = $this->validateDealershipAccess($currentUser, (int) $validated['dealership_id'])) {
                return $accessError;
            }
        }

        $link->update($validated);

        // Загружаем связи для ответа
        $link->load(['creator', 'dealership']);

        return response()->json(ImportantLinkResource::make($link)->resolve());
    }

    public function destroy(Request $request, $id)
    {
        $link = ImportantLink::findOrFail($id);

        // Проверка доступа к дилерству ссылки via Policy
        $this->authorize('delete', $link);

        try {
            $link->delete();

            return response()->json([
                'success' => true,
                'message' => 'Ссылка успешно удалена',
            ], 200);
        } catch (\Exception $e) {
            Log::error('ImportantLink deletion failed', [
                'link_id' => $link->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка при удалении ссылки',
                'error' => config('app.debug') ? $e->getMessage() : 'Внутренняя ошибка сервера',
            ], 500);
        }
    }
}
