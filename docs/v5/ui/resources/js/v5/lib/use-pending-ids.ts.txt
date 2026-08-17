import { useCallback, useState } from 'react';

export function usePendingIds<T extends string | number>() {
    const [pendingIds, setPendingIds] = useState<Set<T>>(() => new Set());

    const start = useCallback((id: T): void => {
        setPendingIds((currentIds) => new Set(currentIds).add(id));
    }, []);

    const finish = useCallback((id: T): void => {
        setPendingIds((currentIds) => {
            const nextIds = new Set(currentIds);
            nextIds.delete(id);

            return nextIds;
        });
    }, []);

    const has = useCallback((id: T): boolean => pendingIds.has(id), [pendingIds]);

    return {
        pendingIds,
        has,
        hasAny: pendingIds.size > 0,
        start,
        finish,
    };
}
