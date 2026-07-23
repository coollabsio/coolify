export function statusBadgeClass(status: string): string {
    if (status === 'running') {
        return 'bg-emerald-500/15 text-emerald-400';
    }

    if (['creating', 'starting'].includes(status)) {
        return 'bg-warning/15 text-warning';
    }

    if (status === 'unknown') {
        return 'bg-muted text-muted-foreground';
    }

    if (['failed', 'exited', 'unreachable'].includes(status)) {
        return 'bg-destructive/15 text-destructive';
    }

    return 'bg-muted text-muted-foreground';
}
