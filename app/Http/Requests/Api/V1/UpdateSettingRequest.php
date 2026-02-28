<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

/**
 * Form Request для обновления глобальной настройки.
 */
class UpdateSettingRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return true; // route middleware: role:owner
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'value' => 'required',
            'type' => 'nullable|in:string,integer,boolean,json,time',
            'description' => 'nullable|string|max:255',
        ];
    }
}
