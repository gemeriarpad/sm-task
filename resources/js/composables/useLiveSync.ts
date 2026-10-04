import { router, usePoll } from '@inertiajs/vue3';
import { echoIsConfigured, useConnectionStatus, useEcho } from '@laravel/echo-vue';
import type { MaybeRefOrGetter } from 'vue';
import { computed, onUnmounted, ref, toValue, watch } from 'vue';

type Options = {
    /** Inertia props to reload when a sync update arrives (partial reload). */
    only: string[];
    /** Whether a sync is queued or running; drives the polling fallback. */
    pollWhile: MaybeRefOrGetter<boolean>;
    /** Polling interval in ms when there is no WebSocket connection. */
    interval?: number;
    /** Broadcast events (on the private "sync" channel) that trigger a reload. */
    events?: string[];
    /** Only reload for events whose payload passes this check. */
    filter?: (payload: Record<string, unknown>) => boolean;
};

/**
 * Keep a page's sync data fresh.
 *
 * With Reverb configured, the page listens on the private "sync" channel and
 * reloads the given props whenever a target's sync state changes. Without a live
 * connection (Reverb not running or not configured) it falls back to polling
 * while a sync is in progress.
 */
export function useLiveSync({ only, pollWhile, interval = 3000, events = ['.target.sync.updated'], filter }: Options) {
    const live = echoIsConfigured();
    const status = live ? useConnectionStatus() : ref('disconnected');
    const connected = computed(() => status.value === 'connected');

    // Several targets can change in quick succession (e.g. "Sync all"): reload at
    // most once per burst.
    let timer: ReturnType<typeof setTimeout> | undefined;
    const reload = (): void => {
        if (timer) {
            return;
        }

        timer = setTimeout(() => {
            timer = undefined;
            router.reload({ only });
        }, 300);
    };

    if (live) {
        useEcho<Record<string, unknown>>('sync', events, (payload) => (!filter || filter(payload)) && reload());
    }

    // Catch up on anything missed while the socket was down. Not on the first
    // connect after a page load: the page has just received fresh props.
    let hasConnected = connected.value;
    watch(connected, (isConnected) => {
        if (isConnected && hasConnected) {
            reload();
        }

        hasConnected ||= isConnected;
    });

    const { start, stop } = usePoll(interval, { only }, { autoStart: false });

    watch([() => toValue(pollWhile), connected], ([active, isConnected]) => (active && !isConnected ? start() : stop()), { immediate: true });

    onUnmounted(() => clearTimeout(timer));

    return { connected };
}
