<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDateTime, formatRelative } from '@/lib/format';
import type { ApiAbilityOption, ApiToken, BreadcrumbItem, NewApiToken } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { Check, Copy, KeyRound, LoaderCircle, Plus, Trash2 } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const page = usePage<{
    tokens: ApiToken[];
    abilities: ApiAbilityOption[];
    newToken: NewApiToken | null;
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'API tokens', href: route('api-tokens.index') }];

const form = useForm<{ name: string; abilities: string[] }>({ name: '', abilities: ['read'] });

function setAbility(value: string, checked: boolean): void {
    form.abilities = checked ? [...form.abilities, value] : form.abilities.filter((ability) => ability !== value);
}

function submit(): void {
    form.post(route('api-tokens.store'), { preserveScroll: true, onSuccess: () => form.reset() });
}

// The clipboard API needs a secure context (https or localhost); the token stays
// selectable in the input either way.
const copied = ref(false);
async function copy(token: string): Promise<void> {
    try {
        await navigator.clipboard.writeText(token);
        copied.value = true;
        setTimeout(() => (copied.value = false), 2000);
    } catch {
        copied.value = false;
    }
}

const revoking = ref<ApiToken | null>(null);
const revokeOpen = computed({
    get: () => revoking.value !== null,
    set: (open: boolean) => {
        if (!open) {
            revoking.value = null;
        }
    },
});
const revokeProcessing = ref(false);

function revoke(): void {
    if (!revoking.value) {
        return;
    }

    router.delete(route('api-tokens.destroy', { tokenId: revoking.value.id }), {
        preserveScroll: true,
        onStart: () => (revokeProcessing.value = true),
        onFinish: () => {
            revokeProcessing.value = false;
            revoking.value = null;
        },
    });
}

const exampleUrl = route('api.v1.targets.index');
</script>

<template>
    <Head title="API tokens" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex w-full flex-col gap-6 p-4">
            <Heading
                title="API tokens"
                description="Tokens for the REST API: a mobile app, a CI job that triggers syncs, or another backend."
                :icon="KeyRound"
            />

            <!-- New token, shown once -->
            <div
                v-if="page.props.newToken"
                class="space-y-2 rounded-xl border border-emerald-300 bg-emerald-50 p-4 dark:border-emerald-900 dark:bg-emerald-950/40 lg:max-w-2xl"
            >
                <p class="text-sm font-medium">Token "{{ page.props.newToken.name }}" created. Copy it now: it won't be shown again.</p>
                <div class="flex gap-2">
                    <Input
                        :model-value="page.props.newToken.token"
                        readonly
                        class="font-mono text-xs"
                        @focus="($event.target as HTMLInputElement).select()"
                    />
                    <Button type="button" variant="outline" @click="copy(page.props.newToken.token)">
                        <Check v-if="copied" class="size-4" />
                        <Copy v-else class="size-4" />
                        {{ copied ? 'Copied' : 'Copy' }}
                    </Button>
                </div>
                <p class="text-xs text-muted-foreground">
                    Send it as <code class="rounded bg-muted px-1 py-0.5">Authorization: Bearer &lt;token&gt;</code>, e.g.
                    <code class="rounded bg-muted px-1 py-0.5">curl -H "Authorization: Bearer …" {{ exampleUrl }}</code>
                </p>
            </div>

            <!-- Create -->
            <form class="space-y-4 rounded-xl border bg-card p-4 lg:max-w-2xl" @submit.prevent="submit">
                <div class="space-y-1">
                    <Label for="token-name">Name</Label>
                    <Input id="token-name" v-model="form.name" autocomplete="off" placeholder="e.g. mobile app, CI sync hook" />
                    <InputError :message="form.errors.name" />
                </div>

                <fieldset class="space-y-2">
                    <legend class="text-sm font-medium">Abilities</legend>
                    <Label
                        v-for="ability in page.props.abilities"
                        :key="ability.value"
                        :for="`ability-${ability.value}`"
                        class="flex items-start gap-3"
                    >
                        <Checkbox
                            :id="`ability-${ability.value}`"
                            :checked="form.abilities.includes(ability.value)"
                            @update:checked="(checked: boolean) => setAbility(ability.value, checked)"
                        />
                        <span class="space-y-0.5">
                            <span class="block">{{ ability.label }}</span>
                            <span class="block text-xs font-normal text-muted-foreground">{{ ability.description }}</span>
                        </span>
                    </Label>
                    <InputError :message="form.errors.abilities" />
                </fieldset>

                <Button type="submit" :disabled="form.processing">
                    <LoaderCircle v-if="form.processing" class="size-4 animate-spin" />
                    <Plus v-else class="size-4" />
                    Create token
                </Button>
            </form>

            <!-- Existing tokens -->
            <div class="rounded-xl border bg-card">
                <Table v-if="page.props.tokens.length > 0">
                    <TableHeader>
                        <TableRow>
                            <TableHead>Name</TableHead>
                            <TableHead>Abilities</TableHead>
                            <TableHead>Last used</TableHead>
                            <TableHead>Created</TableHead>
                            <TableHead class="w-16" />
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow v-for="token in page.props.tokens" :key="token.id">
                            <TableCell class="font-medium">{{ token.name }}</TableCell>
                            <TableCell>
                                <div class="flex flex-wrap gap-1">
                                    <Badge v-for="ability in token.abilities" :key="ability" variant="outline">{{ ability }}</Badge>
                                </div>
                            </TableCell>
                            <TableCell class="text-muted-foreground" :title="formatDateTime(token.last_used_at)">
                                {{ token.last_used_at ? formatRelative(token.last_used_at) : 'Never' }}
                            </TableCell>
                            <TableCell class="whitespace-nowrap text-muted-foreground">{{ formatDateTime(token.created_at, '—') }}</TableCell>
                            <TableCell class="text-right">
                                <Button variant="ghost" size="icon" :aria-label="`Revoke ${token.name}`" title="Revoke" @click="revoking = token">
                                    <Trash2 class="size-4" />
                                </Button>
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
                <p v-else class="p-6 text-center text-sm text-muted-foreground">No tokens yet.</p>
            </div>
        </div>

        <Dialog v-model:open="revokeOpen">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Revoke "{{ revoking?.name }}"?</DialogTitle>
                    <DialogDescription>Requests using this token fail with 401 Unauthenticated right away. This can't be undone.</DialogDescription>
                </DialogHeader>
                <DialogFooter class="gap-2">
                    <Button variant="ghost" @click="revokeOpen = false">Cancel</Button>
                    <Button variant="destructive" :disabled="revokeProcessing" @click="revoke">
                        <LoaderCircle v-if="revokeProcessing" class="size-4 animate-spin" />
                        <Trash2 v-else class="size-4" />
                        Revoke token
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
