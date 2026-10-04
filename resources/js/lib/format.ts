/** Date only, in the viewer's locale; a dash when missing. */
export const formatDate = (iso: string | null): string => (iso ? new Date(iso).toLocaleDateString() : '—');

/** Date and time in the viewer's locale. */
export const formatDateTime = (iso: string | null, fallback = 'Never'): string => (iso ? new Date(iso).toLocaleString() : fallback);

/** A size in KB (as GitHub reports it) as "850 KB" or "6.1 MB"; a dash when unknown. */
export const formatSize = (kb: number | null): string => {
    if (kb === null) {
        return '—';
    }

    return kb < 1024 ? `${kb} KB` : `${(kb / 1024).toFixed(1)} MB`;
};

/** A number with thousands separators. */
export const formatCount = (count: number): string => count.toLocaleString();

/** A duration in milliseconds as "850 ms" or "12.4 s". */
export const formatDuration = (ms: number | null): string => {
    if (ms === null) {
        return '—';
    }

    return ms < 1000 ? `${ms} ms` : `${(ms / 1000).toFixed(1)} s`;
};

/** "3 minutes ago" style relative time. */
export const formatRelative = (iso: string | null): string => {
    if (!iso) {
        return 'never';
    }

    const seconds = Math.round((new Date(iso).getTime() - Date.now()) / 1000);
    const units: [Intl.RelativeTimeFormatUnit, number][] = [
        ['year', 31_536_000],
        ['month', 2_592_000],
        ['day', 86_400],
        ['hour', 3_600],
        ['minute', 60],
    ];
    const rtf = new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' });

    for (const [unit, size] of units) {
        if (Math.abs(seconds) >= size) {
            return rtf.format(Math.round(seconds / size), unit);
        }
    }

    return rtf.format(seconds, 'second');
};
