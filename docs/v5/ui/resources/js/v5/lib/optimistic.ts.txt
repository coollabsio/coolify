export type OptimisticRequestResult<TPayload> =
    | { ok: true; payload: TPayload }
    | { ok: false; errorMessage?: string };

export type OptimisticUpdateOptions<TPayload> = {
    /** Apply the optimistic state change before the request is sent. */
    apply?: () => void;
    /** Restore the previous state after a failed request. */
    rollback?: () => void;
    /** Perform the persistence request; return ok=false (or throw) on failure. */
    request: () => Promise<OptimisticRequestResult<TPayload>>;
    /** Notice used when the failure carries no specific error message. */
    fallbackErrorMessage: string;
    notify: (message: string | null) => void;
    /** Reconcile local state with the server payload after success. */
    onSuccess?: (payload: TPayload) => void;
    /** Runs after success or failure, e.g. to clear pending markers. */
    onSettled?: () => void;
};

/**
 * Unified optimistic-update flow: apply the local change, persist it, and on
 * any failure roll the local state back and surface a notice.
 */
export async function runOptimisticUpdate<TPayload = void>({
    apply,
    rollback,
    request,
    fallbackErrorMessage,
    notify,
    onSuccess,
    onSettled,
}: OptimisticUpdateOptions<TPayload>): Promise<boolean> {
    apply?.();

    try {
        const result = await request();

        if (!result.ok) {
            rollback?.();
            notify(result.errorMessage ?? fallbackErrorMessage);

            return false;
        }

        onSuccess?.(result.payload);

        return true;
    } catch (error) {
        rollback?.();
        notify(error instanceof Error ? error.message : fallbackErrorMessage);

        return false;
    } finally {
        onSettled?.();
    }
}
