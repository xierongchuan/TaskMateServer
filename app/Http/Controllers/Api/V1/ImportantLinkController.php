<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreImportantLinkRequest;
use App\Http\Requests\Api\V1\UpdateImportantLinkRequest;
use App\Http\Resources\ImportantLinkResource;
use App\Models\ImportantLink;
use App\Traits\ApiResponses;
use App\Traits\HasDealershipAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ImportantLinkController extends Controller
{
    use ApiResponses, HasDealershipAccess;

    public function index(Request $request)
    {
        /** @var \App\Models\User $currentUser */
        $currentUser = $request->user();
        $perPage = (int) $request->query('per_page', '15');
        $dealershipId = $this->parseDealershipId($request);
        $isActive = $request->query('is_active');
        $search = $request->query('search');

        $query = ImportantLink::with(['creator', 'dealership']);

        if ($dealershipId !== null) {
            if ($accessError = $this->validateDealershipAccess($currentUser, $dealershipId)) {
                return $accessError;
            }

            $query->where('dealership_id', $dealershipId);
        } else {
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

        $linksData = $links->getCollection()->map(fn ($link) => ImportantLinkResource::make($link)->resolve());

        return response()->json([
            'success' => true,
            'data' => $linksData,
            'current_page' => $links->currentPage(),
            'last_page' => $links->lastPage(),
            'per_page' => $links->perPage(),
            'total' => $links->total(),
            'links' => [
                'first' => $links->url(1),
                'last' => $links->url($links->lastPage()),
                'prev' => $links->previousPageUrl(),
                'next' => $links->nextPageUrl(),
            ],
        ]);
    }

    public function show(Request $request, $id)
    {
        $link = ImportantLink::with(['creator', 'dealership'])->findOrFail($id);

        // Проверка доступа к дилерству ссылки via Policy
        $this->authorize('view', $link);

        return $this->successResponse(ImportantLinkResource::make($link)->resolve());
    }

    public function store(StoreImportantLinkRequest $request)
    {
        /** @var \App\Models\User $currentUser */
        $currentUser = $request->user();

        $validated = $request->validated();
        Log::info('ImportantLink store request', ['title' => $validated['title'] ?? null]);

        $this->authorize('create', ImportantLink::class);

        $dealershipId = $this->normalizeDealershipId($validated['dealership_id']);

        if ($accessError = $this->validateDealershipAccess($currentUser, $dealershipId)) {
            return $accessError;
        }

        $validated['dealership_id'] = $dealershipId;

        // Устанавливаем creator_id из текущего пользователя
        $validated['creator_id'] = $currentUser->id;

        $link = ImportantLink::create($validated);

        // Загружаем связи для ответа
        $link->load(['creator', 'dealership']);

        return $this->createdResponse(ImportantLinkResource::make($link)->resolve(), 'Ссылка создана');
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
        if (array_key_exists('dealership_id', $validated)) {
            $validated['dealership_id'] = $this->normalizeDealershipId($validated['dealership_id']);

            if ($validated['dealership_id'] === null && ! $this->isOwner($currentUser)) {
                return response()->json([
                    'message' => 'The dealership id field is required.',
                    'errors' => [
                        'dealership_id' => ['The dealership id field is required.'],
                    ],
                ], 422);
            }
        }

        if (array_key_exists('dealership_id', $validated) && $validated['dealership_id'] !== $link->dealership_id) {
            if ($accessError = $this->validateDealershipAccess($currentUser, $validated['dealership_id'])) {
                return $accessError;
            }
        }

        $link->update($validated);

        // Загружаем связи для ответа
        $link->load(['creator', 'dealership']);

        return $this->successResponse(ImportantLinkResource::make($link)->resolve());
    }

    public function destroy(Request $request, $id)
    {
        $link = ImportantLink::findOrFail($id);

        // Проверка доступа к дилерству ссылки via Policy
        $this->authorize('delete', $link);

        try {
            $link->delete();

            return $this->deletedResponse('Ссылка успешно удалена');
        } catch (\Exception $e) {
            Log::error('ImportantLink deletion failed', [
                'link_id' => $link->id,
                'error' => $e->getMessage(),
            ]);

            return $this->serverErrorResponse('Ошибка при удалении ссылки', $e);
        }
    }

    private function normalizeDealershipId(mixed $dealershipId): ?int
    {
        return $dealershipId === null ? null : (int) $dealershipId;
    }
}
