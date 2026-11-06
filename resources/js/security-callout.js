// The dashboard callout "{n} resources need a security update now." stays dismissed until a resource that was not
// dismissed needs an update now. localStorage (one key per team) keeps the dismissed resource UUIDs; the page removes
// them when no resource needs an update now (`reset`), so the next one shows the callout again.
function dismissedSet(stored) {
    try {
        const parsed = JSON.parse(stored ?? 'null');
        return Array.isArray(parsed) ? new Set(parsed.map(String)) : null;
    } catch {
        return null;
    }
}

export function securityCallout(current, stored) {
    const dismissed = dismissedSet(stored);
    if (current.length === 0) {
        return { visible: false, reset: dismissed !== null };
    }

    return { visible: dismissed === null || current.some((uuid) => !dismissed.has(String(uuid))), reset: false };
}

// The value to store when the callout is dismissed: the current resources and the ones dismissed before.
export function dismissSecurityCallout(current, stored) {
    return JSON.stringify([...new Set([...(dismissedSet(stored) ?? []), ...current.map(String)])]);
}
