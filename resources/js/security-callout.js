// The dashboard callout "{n} resources need a security update now." stays dismissed until the number goes up.
// localStorage keeps the dismissed number. A lower number replaces it, so the next rise shows the callout again.
export const SECURITY_CALLOUT_KEY = 'coolify.image-scan.dashboard-update-now-dismissed';

export function securityCallout(count, stored) {
    const dismissed = /^\d+$/.test(String(stored ?? '')) ? Number(stored) : null;
    if (dismissed === null) {
        return { visible: count > 0, dismissed: null };
    }

    return { visible: count > dismissed, dismissed: Math.min(count, dismissed) };
}
