/** A REST API token of the current user (never the token itself). */
export type ApiToken = {
    id: number;
    name: string;
    abilities: string[];
    last_used_at: string | null;
    created_at: string | null;
};

/** An ability a token can be given, see App\Enums\ApiAbility. */
export type ApiAbilityOption = {
    value: string;
    label: string;
    description: string;
};

/** Flashed once after creating a token: the only time the plain token is shown. */
export type NewApiToken = {
    name: string;
    token: string;
};
