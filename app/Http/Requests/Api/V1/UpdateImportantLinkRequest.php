<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Form Request для обновления важной ссылки.
 */
class UpdateImportantLinkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware: role:manager,owner
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => 'sometimes|required|string|max:255',
            'url' => 'sometimes|required|string|max:1000|url',
            'description' => 'nullable|string',
            'category' => 'nullable|string|max:50',
            'dealership_id' => 'nullable|integer|exists:auto_dealerships,id',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
