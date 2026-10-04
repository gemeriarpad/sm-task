<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\TargetStatus;
use App\Enums\TargetType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Query parameters of the API's target list. Unlike the web table, which ignores
 * invalid values, the API rejects them with a 422 so clients notice typos.
 */
class TargetIndexRequest extends FormRequest
{
    public const SORTS = ['name', 'status', 'last_synced_at', 'created_at'];

    public const PER_PAGE_OPTIONS = [10, 25, 50, 100];

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:255'],
            'type' => ['nullable', Rule::enum(TargetType::class)],
            'status' => ['nullable', Rule::enum(TargetStatus::class)],
            'sort' => ['nullable', Rule::in(self::SORTS)],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', Rule::in(self::PER_PAGE_OPTIONS)],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
