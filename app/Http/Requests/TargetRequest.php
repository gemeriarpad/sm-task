<?php

namespace App\Http\Requests;

use App\Enums\RepositoryScope;
use App\Enums\TargetType;
use App\Models\Target;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TargetRequest extends FormRequest
{
    /**
     * GitHub login format: alphanumeric characters or hyphens, up to 39
     * characters, not starting or ending with a hyphen.
     */
    public const NAME_PATTERN = '/^[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?$/';

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        // The account is fixed once added: tracking another one means deleting the
        // target and adding a new one, so its repositories and clones never mix.
        if ($this->route('target')) {
            return [
                'name' => ['prohibited'],
                'type' => ['prohibited'],
                'clone_repositories' => ['sometimes', 'boolean'],
                'repository_scope' => ['sometimes', Rule::enum(RepositoryScope::class)],
            ];
        }

        return [
            'name' => [
                'required',
                'string',
                'max:39',
                'regex:'.self::NAME_PATTERN,
                $this->uniqueName(...),
            ],
            'type' => ['required', Rule::enum(TargetType::class)],
            'clone_repositories' => ['sometimes', 'boolean'],
            'repository_scope' => ['sometimes', Rule::enum(RepositoryScope::class)],
            // Filled from a successful GitHub check in the form, omitted otherwise.
            'github_id' => [
                'sometimes',
                'nullable',
                'integer',
                'min:1',
                Rule::unique('targets', 'github_id'),
            ],
            'avatar_url' => ['sometimes', 'nullable', 'url', 'starts_with:https://avatars.githubusercontent.com/'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.regex' => 'The name may only contain letters, numbers and hyphens, and cannot start or end with a hyphen.',
            'github_id.unique' => 'This GitHub account has already been added under another name.',
            'name.prohibited' => 'The GitHub name cannot be changed. Delete the target and add a new one instead.',
            'type.prohibited' => 'The type cannot be changed. Delete the target and add a new one instead.',
        ];
    }

    /**
     * GitHub logins are case-insensitive, so reject a name that only differs in case
     * from an existing target.
     */
    private function uniqueName(string $attribute, mixed $value, Closure $fail): void
    {
        $exists = Target::query()
            ->whereRaw('lower(name) = ?', [mb_strtolower((string) $value)])
            ->exists();

        if ($exists) {
            $fail('This target has already been added.');
        }
    }
}
