<?php

namespace App\Http\Controllers;

use App\Enums\ApiAbility;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * The current user's REST API tokens: create (the plain token is shown once) and revoke.
 */
class ApiTokenController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('api-tokens/Index', [
            'tokens' => $request->user()->tokens()->latest()->latest('id')->get()->map(fn (PersonalAccessToken $token) => [
                'id' => $token->id,
                'name' => $token->name,
                'abilities' => $token->abilities,
                'last_used_at' => $token->last_used_at?->toIso8601String(),
                'created_at' => $token->created_at?->toIso8601String(),
            ]),
            'abilities' => array_map(fn (ApiAbility $ability) => [
                'value' => $ability->value,
                'label' => $ability->label(),
                'description' => $ability->description(),
            ], ApiAbility::cases()),
            // Flashed by store(): the only time the plain token is available.
            'newToken' => $request->session()->get('newToken'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'abilities' => ['required', 'array', 'min:1'],
            'abilities.*' => ['distinct', Rule::enum(ApiAbility::class)],
        ], [
            'abilities.required' => 'Pick at least one ability.',
            'abilities.min' => 'Pick at least one ability.',
        ]);

        $token = $request->user()->createToken($validated['name'], $validated['abilities']);

        return to_route('api-tokens.index')->with('newToken', [
            'name' => $validated['name'],
            'token' => $token->plainTextToken,
        ]);
    }

    /**
     * Revoke one of the user's own tokens; any other id is a 404.
     */
    public function destroy(Request $request, int $tokenId): RedirectResponse
    {
        $request->user()->tokens()->whereKey($tokenId)->firstOrFail()->delete();

        return to_route('api-tokens.index');
    }
}
