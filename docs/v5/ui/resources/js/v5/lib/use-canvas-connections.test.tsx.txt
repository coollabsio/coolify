// @vitest-environment jsdom
import { act, renderHook, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import {
    activeConnectionPorts,
    connectionDirectionKey,
    pruneConnectionPortsByDirection,
    useCanvasConnections,
} from '@/lib/use-canvas-connections';
import type { V5ResourceConnection } from '@/types';

vi.mock('@/lib/canvas-api', () => ({
    canvasRequest: vi.fn(),
}));

import { canvasRequest } from '@/lib/canvas-api';

const canvasRequestMock = vi.mocked(canvasRequest);

function connection(overrides: Partial<V5ResourceConnection> = {}): V5ResourceConnection {
    return {
        id: 'connection-1',
        applicationIds: ['app-a', 'app-b'],
        fromApplicationId: 'app-a',
        toApplicationId: 'app-b',
        portsByDirection: { 'app-a->app-b': ['80'] },
        ...overrides,
    };
}

function jsonResponse(payload: unknown, ok = true): Response {
    return { ok, json: async () => payload } as Response;
}

beforeEach(() => {
    canvasRequestMock.mockReset();
});

describe('connection helpers', () => {
    it('builds direction keys and reads active ports', () => {
        expect(connectionDirectionKey('app-a', 'app-b')).toBe('app-a->app-b');
        expect(activeConnectionPorts(connection())).toEqual(['80']);
        expect(activeConnectionPorts(connection({ portsByDirection: {} }))).toEqual([]);
    });

    it('prunes ports to the active direction only', () => {
        const pruned = pruneConnectionPortsByDirection(
            connection({ portsByDirection: { 'app-a->app-b': ['80'], 'app-b->app-a': ['443'] } }),
        );

        expect(pruned).toEqual({ 'app-a->app-b': ['80'] });
    });
});

describe('connectionExists', () => {
    it('matches connections in both directions', () => {
        const { result } = renderHook(() => useCanvasConnections([connection()], vi.fn()));

        expect(result.current.connectionExists('app-a', 'app-b')).toBe(true);
        expect(result.current.connectionExists('app-b', 'app-a')).toBe(true);
        expect(result.current.connectionExists('app-a', 'app-c')).toBe(false);
    });
});

describe('persistNewConnection', () => {
    it('adds and selects the persisted connection on success', async () => {
        const persisted = connection({ id: 'connection-2', fromApplicationId: 'app-a', toApplicationId: 'app-c' });
        canvasRequestMock.mockResolvedValue(jsonResponse({ connection: persisted }));
        const notify = vi.fn();
        const { result } = renderHook(() => useCanvasConnections([connection()], notify));

        await act(() => result.current.persistNewConnection('app-a', 'app-c'));

        expect(canvasRequestMock).toHaveBeenCalledWith('/v5/resource-connections', {
            method: 'POST',
            body: {
                resource_one: { type: 'application', uuid: 'app-a' },
                resource_two: { type: 'application', uuid: 'app-c' },
            },
        });
        expect(result.current.connections.map((candidate) => candidate.id)).toEqual(['connection-1', 'connection-2']);
        expect(result.current.selectedConnectionId).toBe('connection-2');
        expect(notify).toHaveBeenCalledWith(null);
        expect(notify).not.toHaveBeenCalledWith(expect.stringContaining('Could not'));
    });

    it('notifies with message and detail on failure', async () => {
        canvasRequestMock.mockResolvedValue(jsonResponse({ message: 'Connection rejected.', detail: 'Applications overlap.' }, false));
        const notify = vi.fn();
        const { result } = renderHook(() => useCanvasConnections([], notify));

        await act(() => result.current.persistNewConnection('app-a', 'app-b'));

        expect(notify).toHaveBeenLastCalledWith('Connection rejected. Applications overlap.');
        expect(result.current.connections).toEqual([]);
    });
});

describe('addConnectionPort', () => {
    it.each(['', '0', '65536', '8.5', 'http'])('ignores invalid port draft %j', (draft) => {
        const { result } = renderHook(() => useCanvasConnections([connection()], vi.fn()));

        act(() => result.current.setConnectionPortDraft('connection-1', draft));
        act(() => result.current.addConnectionPort('connection-1'));

        expect(canvasRequestMock).not.toHaveBeenCalled();
    });

    it('ignores a port already present for the active direction', () => {
        const { result } = renderHook(() => useCanvasConnections([connection()], vi.fn()));

        act(() => result.current.setConnectionPortDraft('connection-1', '80'));
        act(() => result.current.addConnectionPort('connection-1'));

        expect(canvasRequestMock).not.toHaveBeenCalled();
    });

    it('optimistically adds the port, persists it, and clears the draft', async () => {
        const persisted = connection({ portsByDirection: { 'app-a->app-b': ['80', '443'] } });
        canvasRequestMock.mockResolvedValue(jsonResponse({ connection: persisted }));
        const { result } = renderHook(() => useCanvasConnections([connection()], vi.fn()));

        act(() => result.current.setConnectionPortDraft('connection-1', ' 443 '));
        act(() => result.current.addConnectionPort('connection-1'));

        expect(activeConnectionPorts(result.current.connections[0])).toEqual(['80', '443']);
        expect(result.current.connectionPortInput['connection-1']).toBe('');

        await waitFor(() => {
            expect(canvasRequestMock).toHaveBeenCalledWith('/v5/resource-connections/connection-1', {
                method: 'PATCH',
                body: { ports_by_direction: { 'app-a->app-b': [80, 443] } },
            });
        });
    });

    it('rolls the port back when persistence fails', async () => {
        canvasRequestMock.mockResolvedValue(jsonResponse({ message: 'Could not save allowed ports.' }, false));
        const notify = vi.fn();
        const { result } = renderHook(() => useCanvasConnections([connection()], notify));

        act(() => result.current.setConnectionPortDraft('connection-1', '443'));
        act(() => result.current.addConnectionPort('connection-1'));

        await waitFor(() => {
            expect(activeConnectionPorts(result.current.connections[0])).toEqual(['80']);
        });
        expect(notify).toHaveBeenCalledWith('Could not save allowed ports.');
    });
});

describe('removeConnectionPort', () => {
    it('persists the connection without the removed port', async () => {
        const persisted = connection({ portsByDirection: { 'app-a->app-b': [] } });
        canvasRequestMock.mockResolvedValue(jsonResponse({ connection: persisted }));
        const { result } = renderHook(() => useCanvasConnections([connection()], vi.fn()));

        act(() => result.current.removeConnectionPort('connection-1', '80'));

        expect(activeConnectionPorts(result.current.connections[0])).toEqual([]);

        await waitFor(() => {
            expect(canvasRequestMock).toHaveBeenCalledWith('/v5/resource-connections/connection-1', {
                method: 'PATCH',
                body: { ports_by_direction: { 'app-a->app-b': [] } },
            });
        });
    });
});

describe('deleteConnection', () => {
    it('optimistically removes the connection and keeps it removed on success', async () => {
        canvasRequestMock.mockResolvedValue(jsonResponse({}));
        const { result } = renderHook(() => useCanvasConnections([connection()], vi.fn()));

        act(() => result.current.setSelectedConnectionId('connection-1'));
        act(() => result.current.deleteConnection('connection-1'));

        expect(result.current.connections).toEqual([]);
        expect(result.current.selectedConnectionId).toBeNull();

        await waitFor(() => {
            expect(canvasRequestMock).toHaveBeenCalledWith('/v5/resource-connections/connection-1', { method: 'DELETE' });
        });
        expect(result.current.connections).toEqual([]);
    });

    it('restores the connection at its original index when deletion fails', async () => {
        canvasRequestMock.mockResolvedValue(jsonResponse({}, false));
        const first = connection({ id: 'connection-1' });
        const second = connection({ id: 'connection-2' });
        const notify = vi.fn();
        const { result } = renderHook(() => useCanvasConnections([first, second], notify));

        act(() => result.current.deleteConnection('connection-1'));

        expect(result.current.connections.map((candidate) => candidate.id)).toEqual(['connection-2']);

        await waitFor(() => {
            expect(result.current.connections.map((candidate) => candidate.id)).toEqual(['connection-1', 'connection-2']);
        });
        expect(notify).toHaveBeenCalledWith('Could not delete resource connection.');
    });

    it('does nothing for an unknown connection id', () => {
        const { result } = renderHook(() => useCanvasConnections([connection()], vi.fn()));

        act(() => result.current.deleteConnection('missing'));

        expect(canvasRequestMock).not.toHaveBeenCalled();
        expect(result.current.connections).toHaveLength(1);
    });
});

describe('updateConnectionDirection', () => {
    it('swaps the active direction locally without a request', () => {
        const { result } = renderHook(() => useCanvasConnections([connection()], vi.fn()));

        act(() => result.current.updateConnectionDirection('connection-1', 'app-b', 'app-a'));

        expect(result.current.connections[0]).toMatchObject({ fromApplicationId: 'app-b', toApplicationId: 'app-a' });
        expect(canvasRequestMock).not.toHaveBeenCalled();
    });
});

describe('removeConnectionsForApplication', () => {
    it('drops every connection touching the application', () => {
        const related = connection({ id: 'connection-1' });
        const unrelated = connection({
            id: 'connection-2',
            applicationIds: ['app-c', 'app-d'],
            fromApplicationId: 'app-c',
            toApplicationId: 'app-d',
        });
        const { result } = renderHook(() => useCanvasConnections([related, unrelated], vi.fn()));

        act(() => result.current.removeConnectionsForApplication('app-a'));

        expect(result.current.connections.map((candidate) => candidate.id)).toEqual(['connection-2']);
    });
});

describe('keyboard deletion', () => {
    it('deletes the selected connection on Backspace', async () => {
        canvasRequestMock.mockResolvedValue(jsonResponse({}));
        const { result } = renderHook(() => useCanvasConnections([connection()], vi.fn()));

        act(() => result.current.setSelectedConnectionId('connection-1'));
        act(() => {
            document.body.dispatchEvent(new KeyboardEvent('keydown', { key: 'Backspace', bubbles: true }));
        });

        expect(result.current.connections).toEqual([]);
    });

    it('ignores Backspace while typing in an input', () => {
        const { result } = renderHook(() => useCanvasConnections([connection()], vi.fn()));
        const input = document.createElement('input');
        document.body.appendChild(input);

        act(() => result.current.setSelectedConnectionId('connection-1'));
        act(() => {
            input.dispatchEvent(new KeyboardEvent('keydown', { key: 'Backspace', bubbles: true }));
        });

        expect(result.current.connections).toHaveLength(1);
        input.remove();
    });
});
