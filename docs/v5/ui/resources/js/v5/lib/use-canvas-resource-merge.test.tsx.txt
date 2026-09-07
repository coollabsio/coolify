// @vitest-environment jsdom
import { act, renderHook } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { useCanvasResourceMerge } from '@/lib/use-canvas-resource-merge';
import type { V5CanvasResourceUpdatedEvent } from '@/lib/use-canvas-channel';
import type { V5Application, V5CaddyIngress } from '@/types';

const channelHandlers: Array<(event: V5CanvasResourceUpdatedEvent) => void> = [];

vi.mock('@/lib/use-canvas-channel', () => ({
    useCanvasResourceChannel: (_teamId: number | null, onEvent: (event: V5CanvasResourceUpdatedEvent) => void) => {
        channelHandlers.push(onEvent);
    },
}));

function application(overrides: Partial<V5Application> = {}): V5Application {
    return {
        id: 'app-1',
        name: 'nginx-test',
        status: 'running',
        projectUuid: 'project-1',
        environmentUuid: 'env-1',
        canvasX: 0,
        canvasY: 0,
        ...overrides,
    } as V5Application;
}

function ingress(overrides: Partial<V5CaddyIngress> = {}): V5CaddyIngress {
    return {
        id: 'ingress-1',
        name: 'caddy',
        status: 'running',
        canvasX: 0,
        canvasY: 0,
        ...overrides,
    } as V5CaddyIngress;
}

type HarnessOptions = {
    initialApplications?: V5Application[];
    initialIngresses?: V5CaddyIngress[];
    selectedProjectUuid?: string | null;
    selectedEnvironmentUuid?: string | null;
    locallyPositionedApplicationIds?: Set<string>;
    locallyPositionedIngressIds?: Set<string>;
};

function renderMergeHarness({
    initialApplications = [],
    initialIngresses = [],
    selectedProjectUuid = 'project-1',
    selectedEnvironmentUuid = 'env-1',
    locallyPositionedApplicationIds = new Set<string>(),
    locallyPositionedIngressIds = new Set<string>(),
}: HarnessOptions = {}) {
    let applications = initialApplications;
    let ingresses = initialIngresses;

    const setApplications = (update: V5Application[] | ((current: V5Application[]) => V5Application[])): void => {
        applications = typeof update === 'function' ? update(applications) : update;
    };
    const setIngresses = (update: V5CaddyIngress[] | ((current: V5CaddyIngress[]) => V5CaddyIngress[])): void => {
        ingresses = typeof update === 'function' ? update(ingresses) : update;
    };

    renderHook(() =>
        useCanvasResourceMerge({
            teamId: 1,
            selectedProjectUuid,
            selectedEnvironmentUuid,
            setApplications,
            setIngresses,
            locallyPositionedApplicationIds: { current: locallyPositionedApplicationIds },
            locallyPositionedIngressIds: { current: locallyPositionedIngressIds },
        }),
    );

    const emit = (event: Partial<V5CanvasResourceUpdatedEvent>): void => {
        act(() => {
            channelHandlers.at(-1)?.({ application: null, caddyIngress: null, ...event });
        });
    };

    return {
        emit,
        applications: () => applications,
        ingresses: () => ingresses,
    };
}

beforeEach(() => {
    channelHandlers.length = 0;
});

describe('useCanvasResourceMerge', () => {
    it('updates an existing application in place', () => {
        const harness = renderMergeHarness({ initialApplications: [application()] });

        harness.emit({ application: application({ status: 'failed', canvasX: 100 }) });

        expect(harness.applications()).toEqual([expect.objectContaining({ id: 'app-1', status: 'failed', canvasX: 100 })]);
    });

    it('keeps local canvas position for cards mid-drag', () => {
        const harness = renderMergeHarness({
            initialApplications: [application({ canvasX: 500, canvasY: 300 })],
            locallyPositionedApplicationIds: new Set(['app-1']),
        });

        harness.emit({ application: application({ status: 'failed', canvasX: 0, canvasY: 0 }) });

        expect(harness.applications()).toEqual([
            expect.objectContaining({ id: 'app-1', status: 'failed', canvasX: 500, canvasY: 300 }),
        ]);
    });

    it('appends unknown applications belonging to the selected project and environment', () => {
        const harness = renderMergeHarness({ initialApplications: [application()] });

        harness.emit({ application: application({ id: 'app-2', projectUuid: 'project-1', environmentUuid: 'env-1' }) });

        expect(harness.applications().map((candidate) => candidate.id)).toEqual(['app-1', 'app-2']);
    });

    it('ignores unknown applications from other projects or environments', () => {
        const harness = renderMergeHarness({ initialApplications: [application()] });

        harness.emit({ application: application({ id: 'app-2', projectUuid: 'project-9' }) });
        harness.emit({ application: application({ id: 'app-3', environmentUuid: 'env-9' }) });

        expect(harness.applications().map((candidate) => candidate.id)).toEqual(['app-1']);
    });

    it('never appends when no project or environment is selected', () => {
        const harness = renderMergeHarness({ selectedProjectUuid: null, selectedEnvironmentUuid: null });

        harness.emit({ application: application() });

        expect(harness.applications()).toEqual([]);
    });

    it('merges bulk application payloads', () => {
        const harness = renderMergeHarness({ initialApplications: [application()] });

        harness.emit({
            applications: [application({ status: 'failed' }), application({ id: 'app-2' })],
        });

        expect(harness.applications()).toEqual([
            expect.objectContaining({ id: 'app-1', status: 'failed' }),
            expect.objectContaining({ id: 'app-2' }),
        ]);
    });

    it('updates known ingresses in place but never appends unknown ones', () => {
        const harness = renderMergeHarness({ initialIngresses: [ingress()] });

        harness.emit({ caddyIngress: ingress({ status: 'stopped' }) });
        harness.emit({ caddyIngress: ingress({ id: 'ingress-9' }) });

        expect(harness.ingresses()).toEqual([expect.objectContaining({ id: 'ingress-1', status: 'stopped' })]);
    });

    it('keeps local ingress position for cards mid-drag', () => {
        const harness = renderMergeHarness({
            initialIngresses: [ingress({ canvasX: 250, canvasY: 100 })],
            locallyPositionedIngressIds: new Set(['ingress-1']),
        });

        harness.emit({ caddyIngress: ingress({ status: 'stopped', canvasX: 0, canvasY: 0 }) });

        expect(harness.ingresses()).toEqual([
            expect.objectContaining({ id: 'ingress-1', status: 'stopped', canvasX: 250, canvasY: 100 }),
        ]);
    });
});
