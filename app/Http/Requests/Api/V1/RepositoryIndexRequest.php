<?php

namespace App\Http\Requests\Api\V1;

use App\Queries\RepositoryListQuery;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Query parameters of the API's repository lists: the same search, filters and
 * sorts as the web UI (see RepositoryListQuery), but invalid values are a 422.
 */
class RepositoryIndexRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $toggle = ['nullable', Rule::in(['only', 'hide'])];

        return [
            'search' => ['nullable', 'string', 'max:255'],
            'sort' => ['nullable', Rule::in(array_keys(RepositoryListQuery::SORTS))],
            'language' => ['nullable', 'string', 'max:255'],
            'archived' => $toggle,
            'fork' => $toggle,
            'missing' => $toggle,
            'excluded' => ['nullable', Rule::in(['only', 'include'])],
            'target' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', Rule::in(RepositoryListQuery::PER_PAGE_OPTIONS)],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
