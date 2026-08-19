import { describe, expect, it, vi } from 'vitest';
import { runOptimisticUpdate } from '@/lib/optimistic';

describe('runOptimisticUpdate', () => {
    it('applies, persists, and reconciles on success', async () => {
        const calls: string[] = [];
        const notify = vi.fn();

        const succeeded = await runOptimisticUpdate<string>({
            apply: () => calls.push('apply'),
            rollback: () => calls.push('rollback'),
            request: async () => {
                calls.push('request');

                return { ok: true, payload: 'server-state' };
            },
            fallbackErrorMessage: 'fallback',
            notify,
            onSuccess: (payload) => calls.push(`success:${payload}`),
            onSettled: () => calls.push('settled'),
        });

        expect(succeeded).toBe(true);
        expect(calls).toEqual(['apply', 'request', 'success:server-state', 'settled']);
        expect(notify).not.toHaveBeenCalled();
    });

    it('rolls back and notifies with the server error message on failure', async () => {
        const rollback = vi.fn();
        const notify = vi.fn();

        const succeeded = await runOptimisticUpdate({
            rollback,
            request: async () => ({ ok: false, errorMessage: 'Ports must be integers.' }),
            fallbackErrorMessage: 'fallback',
            notify,
        });

        expect(succeeded).toBe(false);
        expect(rollback).toHaveBeenCalledOnce();
        expect(notify).toHaveBeenCalledWith('Ports must be integers.');
    });

    it('falls back to the generic message when the failure carries none', async () => {
        const notify = vi.fn();

        await runOptimisticUpdate({
            request: async () => ({ ok: false }),
            fallbackErrorMessage: 'Could not save.',
            notify,
        });

        expect(notify).toHaveBeenCalledWith('Could not save.');
    });

    it('rolls back and notifies with the thrown error message', async () => {
        const rollback = vi.fn();
        const notify = vi.fn();
        const onSettled = vi.fn();

        const succeeded = await runOptimisticUpdate({
            rollback,
            request: async () => {
                throw new Error('Network down.');
            },
            fallbackErrorMessage: 'fallback',
            notify,
            onSettled,
        });

        expect(succeeded).toBe(false);
        expect(rollback).toHaveBeenCalledOnce();
        expect(notify).toHaveBeenCalledWith('Network down.');
        expect(onSettled).toHaveBeenCalledOnce();
    });

    it('uses the fallback message for non-Error throws', async () => {
        const notify = vi.fn();

        await runOptimisticUpdate({
            request: async () => {
                throw 'string failure';
            },
            fallbackErrorMessage: 'Could not save.',
            notify,
        });

        expect(notify).toHaveBeenCalledWith('Could not save.');
    });

    it('runs onSettled even when onSuccess throws', async () => {
        const onSettled = vi.fn();

        await expect(
            runOptimisticUpdate({
                request: async () => ({ ok: true, payload: undefined }),
                fallbackErrorMessage: 'fallback',
                notify: vi.fn(),
                onSuccess: () => {
                    throw new Error('reconcile failed');
                },
                onSettled,
            }),
        ).resolves.toBe(false);

        expect(onSettled).toHaveBeenCalledOnce();
    });
});
