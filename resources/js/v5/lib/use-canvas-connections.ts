import { useCallback, useEffect, useRef, useState } from 'react';

import { canvasRequest } from '@/lib/canvas-api';
import { runOptimisticUpdate, type OptimisticRequestResult } from '@/lib/optimistic';
import type { V5ResourceConnection } from '@/types';

export type CanvasConnection = V5ResourceConnection;

export type CanvasNotify = (message: string | null) => void;

export function connectionDirectionKey(fromApplicationId: string, toApplicationId: string): string {
    return `${fromApplicationId}->${toApplicationId}`;
}

export function activeConnectionPorts(connection: CanvasConnection): string[] {
    return connection.portsByDirection[connectionDirectionKey(connection.fromApplicationId, connection.toApplicationId)] ?? [];
}

export function pruneConnectionPortsByDirection(
    connection: CanvasConnection,
    fromApplicationId = connection.fromApplicationId,
    toApplicationId = connection.toApplicationId,
): Record<string, string[]> {
    const directionKey = connectionDirectionKey(fromApplicationId, toApplicationId);

    return {
        [directionKey]: connection.portsByDirection[directionKey] ?? [],
    };
}

function connectionPortsPayload(connection: CanvasConnection): Record<string, number[]> {
    return Object.fromEntries(
        Object.entries(connection.portsByDirection).map(([direction, ports]) => [
            direction,
            ports.map((port) => Number(port)).filter((port) => Number.isInteger(port)),
        ]),
    );
}

function preserveActiveDirection(connection: CanvasConnection, activeConnection: CanvasConnection): CanvasConnection {
    return {
        ...connection,
        fromApplicationId: activeConnection.fromApplicationId,
        toApplicationId: activeConnection.toApplicationId,
    };
}

function normalizeConnection(connection: V5ResourceConnection): CanvasConnection {
    return {
        ...connection,
        applicationIds: [...connection.applicationIds],
        portsByDirection: connection.portsByDirection ?? {},
    };
}

function responseErrorMessage(payload: { message?: string; detail?: string }, fallback: string): string {
    if (payload.message && payload.detail) {
        return `${payload.message} ${payload.detail}`;
    }

    return payload.message ?? fallback;
}

async function parseConnectionResponse(response: Response, fallback: string): Promise<OptimisticRequestResult<CanvasConnection>> {
    const payload = (await response.json()) as { connection?: V5ResourceConnection; message?: string; detail?: string };

    if (!response.ok || !payload.connection) {
        return { ok: false, errorMessage: responseErrorMessage(payload, fallback) };
    }

    return { ok: true, payload: normalizeConnection(payload.connection) };
}

/**
 * Owns the canvas resource connections: selection, per-direction allowed
 * ports, and the optimistic persistence flows for creating, updating, and
 * deleting connections.
 */
export function useCanvasConnections(initialConnections: V5ResourceConnection[], notify: CanvasNotify) {
    const [connections, setConnections] = useState<CanvasConnection[]>(initialConnections);
    const [selectedConnectionId, setSelectedConnectionId] = useState<string | null>(null);
    const [connectionPortInput, setConnectionPortInput] = useState<Record<string, string>>({});
    const connectionsRef = useRef(connections);

    connectionsRef.current = connections;

    const resetConnections = useCallback((nextConnections: V5ResourceConnection[]): void => {
        setConnections(nextConnections);
        setSelectedConnectionId(null);
        setConnectionPortInput({});
    }, []);

    const connectionExists = useCallback(
        (fromApplicationId: string, toApplicationId: string): boolean =>
            connectionsRef.current.some(
                (connection) =>
                    (connection.fromApplicationId === fromApplicationId && connection.toApplicationId === toApplicationId) ||
                    (connection.fromApplicationId === toApplicationId && connection.toApplicationId === fromApplicationId),
            ),
        [],
    );

    const replaceConnection = useCallback((nextConnection: CanvasConnection): void => {
        setConnections((currentConnections) =>
            currentConnections.map((currentConnection) =>
                currentConnection.id === nextConnection.id ? nextConnection : currentConnection,
            ),
        );
    }, []);

    const persistNewConnection = useCallback(
        async (fromApplicationId: string, toApplicationId: string): Promise<void> => {
            notify(null);

            await runOptimisticUpdate<CanvasConnection>({
                request: async () => {
                    const response = await canvasRequest('/v5/resource-connections', {
                        method: 'POST',
                        body: {
                            resource_one: { type: 'application', uuid: fromApplicationId },
                            resource_two: { type: 'application', uuid: toApplicationId },
                        },
                    });

                    return parseConnectionResponse(response, 'Could not save resource connection.');
                },
                fallbackErrorMessage: 'Could not save resource connection.',
                notify,
                onSuccess: (nextConnection) => {
                    setConnections((currentConnections) => {
                        const withoutDuplicate = currentConnections.filter((connection) => connection.id !== nextConnection.id);

                        return [...withoutDuplicate, nextConnection];
                    });
                    setSelectedConnectionId(nextConnection.id);
                },
            });
        },
        [notify],
    );

    const persistConnectionPorts = useCallback(
        async (updatedConnection: CanvasConnection, previousConnection: CanvasConnection): Promise<void> => {
            await runOptimisticUpdate<CanvasConnection>({
                apply: () => replaceConnection(updatedConnection),
                rollback: () => replaceConnection(previousConnection),
                request: async () => {
                    const response = await canvasRequest(`/v5/resource-connections/${updatedConnection.id}`, {
                        method: 'PATCH',
                        body: { ports_by_direction: connectionPortsPayload(updatedConnection) },
                    });

                    return parseConnectionResponse(response, 'Could not save allowed ports.');
                },
                fallbackErrorMessage: 'Could not save allowed ports.',
                notify,
                onSuccess: (nextConnection) => replaceConnection(preserveActiveDirection(nextConnection, updatedConnection)),
            });
        },
        [notify, replaceConnection],
    );

    const deleteConnection = useCallback(
        (connectionId: string): void => {
            const connectionIndex = connectionsRef.current.findIndex((candidate) => candidate.id === connectionId);
            const connection = connectionsRef.current[connectionIndex];

            if (!connection) {
                return;
            }

            setSelectedConnectionId(null);
            void runOptimisticUpdate({
                apply: () => setConnections((currentConnections) => currentConnections.filter((candidate) => candidate.id !== connectionId)),
                rollback: () =>
                    setConnections((currentConnections) => {
                        if (currentConnections.some((candidate) => candidate.id === connectionId)) {
                            return currentConnections;
                        }

                        const restoredConnections = [...currentConnections];

                        restoredConnections.splice(Math.min(connectionIndex, restoredConnections.length), 0, connection);

                        return restoredConnections;
                    }),
                request: async () => {
                    const response = await canvasRequest(`/v5/resource-connections/${connectionId}`, { method: 'DELETE' });

                    return response.ok ? { ok: true, payload: undefined } : { ok: false };
                },
                fallbackErrorMessage: 'Could not delete resource connection.',
                notify,
            });
        },
        [notify],
    );

    const updateConnectionDirection = useCallback(
        (connectionId: string, fromApplicationId: string, toApplicationId: string): void => {
            const connection = connectionsRef.current.find((candidate) => candidate.id === connectionId);

            if (!connection) {
                return;
            }

            const updatedConnection = {
                ...connection,
                fromApplicationId,
                toApplicationId,
            };

            replaceConnection(updatedConnection);
        },
        [replaceConnection],
    );

    const setConnectionPortDraft = useCallback((connectionId: string, value: string): void => {
        setConnectionPortInput((currentInputs) => ({ ...currentInputs, [connectionId]: value }));
    }, []);

    const addConnectionPort = useCallback(
        (connectionId: string): void => {
            const port = connectionPortInput[connectionId]?.trim();
            const portNumber = Number(port);

            if (!port || !Number.isInteger(portNumber) || portNumber < 1 || portNumber > 65535) {
                return;
            }

            const connection = connectionsRef.current.find((candidate) => candidate.id === connectionId);

            if (!connection) {
                return;
            }

            const directionKey = connectionDirectionKey(connection.fromApplicationId, connection.toApplicationId);
            const directionPorts = connection.portsByDirection[directionKey] ?? [];

            if (directionPorts.includes(port)) {
                return;
            }

            const updatedConnection = {
                ...connection,
                portsByDirection: {
                    ...connection.portsByDirection,
                    [directionKey]: [...directionPorts, port],
                },
            };

            void persistConnectionPorts(updatedConnection, connection);
            setConnectionPortInput((currentInputs) => ({ ...currentInputs, [connectionId]: '' }));
        },
        [connectionPortInput, persistConnectionPorts],
    );

    const removeConnectionPort = useCallback(
        (connectionId: string, port: string): void => {
            const connection = connectionsRef.current.find((candidate) => candidate.id === connectionId);

            if (!connection) {
                return;
            }

            const directionKey = connectionDirectionKey(connection.fromApplicationId, connection.toApplicationId);
            const updatedConnection = {
                ...connection,
                portsByDirection: {
                    ...connection.portsByDirection,
                    [directionKey]: activeConnectionPorts(connection).filter((allowedPort) => allowedPort !== port),
                },
            };

            void persistConnectionPorts(updatedConnection, connection);
        },
        [persistConnectionPorts],
    );

    const removeConnectionsForApplication = useCallback((applicationId: string): void => {
        setConnections((currentConnections) =>
            currentConnections.filter(
                (connection) => connection.fromApplicationId !== applicationId && connection.toApplicationId !== applicationId,
            ),
        );
    }, []);

    useEffect(() => {
        function deleteSelectedConnection(event: KeyboardEvent): void {
            const target = event.target as HTMLElement | null;

            if (target?.closest('input, textarea, [contenteditable="true"]')) {
                return;
            }

            if (!['Backspace', 'Delete'].includes(event.key) || !selectedConnectionId) {
                return;
            }

            deleteConnection(selectedConnectionId);
        }

        window.addEventListener('keydown', deleteSelectedConnection);

        return () => window.removeEventListener('keydown', deleteSelectedConnection);
    }, [selectedConnectionId, deleteConnection]);

    return {
        connections,
        selectedConnectionId,
        setSelectedConnectionId,
        connectionPortInput,
        setConnectionPortDraft,
        resetConnections,
        connectionExists,
        persistNewConnection,
        deleteConnection,
        updateConnectionDirection,
        addConnectionPort,
        removeConnectionPort,
        removeConnectionsForApplication,
    };
}
