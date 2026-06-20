import { Head } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState, type MouseEvent, type PointerEvent, type WheelEvent } from 'react';

import { AppNavbar } from '@/components/app-navbar';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Field, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { resolveCanvasNodeLayout, resolveCanvasNodePosition, type CanvasNodeBounds } from '@/lib/canvas-collision';
import { csrfToken } from '@/lib/csrf';
import { cn } from '@/lib/utils';
import type { V5Application, V5CaddyIngress, V5DashboardProps, V5ResourceConnection } from '@/types';

type Viewport = {
    x: number;
    y: number;
    zoom: number;
};

type ConnectorSide = 'top' | 'right' | 'bottom' | 'left';

type ConnectionEndpoint = {
    applicationId: string;
    side: ConnectorSide;
};

type V5CanvasResourceUpdatedEvent = {
    application: V5Application | null;
    caddyIngress: V5CaddyIngress | null;
};

type IngressModalState = {
    application: V5Application;
    domains: string;
    internalPort: string;
    error: string | null;
};

type EchoChannel = {
    listen: (event: string, callback: (payload: unknown) => void) => EchoChannel;
    subscribed?: (callback: () => void) => EchoChannel;
    error?: (callback: (error: unknown) => void) => EchoChannel;
};

type EchoClient = {
    private: (channel: string) => EchoChannel;
    leave?: (channel: string) => void;
    leaveChannel?: (channel: string) => void;
};

declare global {
    interface Window {
        Echo?: EchoClient;
    }
}

type CanvasConnection = V5ResourceConnection;

type DraftConnection = {
    from: ConnectionEndpoint;
    toX: number;
    toY: number;
};

type PointerState =
    | {
          type: 'pan';
          pointerId: number;
          startClientX: number;
          startClientY: number;
          startViewport: Viewport;
      }
    | {
          type: 'app';
          pointerId: number;
          applicationId: string;
          startClientX: number;
          startClientY: number;
          startX: number;
          startY: number;
      }
    | {
          type: 'ingress';
          pointerId: number;
          ingressId: string;
          startClientX: number;
          startClientY: number;
          startX: number;
          startY: number;
      }
    | {
          type: 'connection';
          pointerId: number;
          from: ConnectionEndpoint;
      };

const APPLICATION_CARD_WIDTH = 320;
const APPLICATION_CARD_HEIGHT = 136;
const CANVAS_CARD_GAP = 16;
const CONNECTOR_SIDES: ConnectorSide[] = ['top', 'right', 'bottom', 'left'];
const MIN_CANVAS_ZOOM = 0.5;
const MAX_CANVAS_ZOOM = 2;
const CANVAS_ZOOM_STEP = 0.1;
const PINCH_CANVAS_ZOOM_STEP = 0.03;

async function persistApplicationPosition(application: V5Application): Promise<void> {
    await fetch(`/v5/applications/${application.id}/position`, {
        method: 'PATCH',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
        },
        body: JSON.stringify({
            canvas_x: application.canvasX,
            canvas_y: application.canvasY,
        }),
    });
}

async function persistCaddyIngressPosition(ingress: V5CaddyIngress): Promise<void> {
    await fetch(`/v5/caddy-ingresses/${ingress.id}/position`, {
        method: 'PATCH',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
        },
        body: JSON.stringify({
            canvas_x: ingress.canvasX,
            canvas_y: ingress.canvasY,
        }),
    });
}

export default function Dashboard({
    flux,
    currentTeam = null,
    applications: initialApplications = [],
    caddyIngresses = [],
    resourceConnections: initialResourceConnections = [],
    nginxServers = [],
    projects = [],
    selectedProjectUuid = null,
    selectedEnvironmentUuid = null,
}: V5DashboardProps) {
    const [applications, setApplications] = useState<V5Application[]>(initialApplications);
    const [ingresses, setIngresses] = useState<V5CaddyIngress[]>(caddyIngresses);
    const [connections, setConnections] = useState<CanvasConnection[]>(initialResourceConnections);
    const [selectedConnectionId, setSelectedConnectionId] = useState<string | null>(null);
    const [selectedApplicationId, setSelectedApplicationId] = useState<string | null>(null);
    const [connectionPortInput, setConnectionPortInput] = useState<Record<string, string>>({});
    const [draftConnection, setDraftConnection] = useState<DraftConnection | null>(null);
    const [viewport, setViewport] = useState<Viewport>({ x: 0, y: 0, zoom: 1 });
    const [pointerState, setPointerState] = useState<PointerState | null>(null);
    const [isCreating, setIsCreating] = useState(false);
    const [selectedNginxServerId, setSelectedNginxServerId] = useState<string>(nginxServers[0]?.id ?? '');
    const [isRefreshing, setIsRefreshing] = useState(false);
    const [notice, setNotice] = useState<string | null>(null);
    const [ingressModal, setIngressModal] = useState<IngressModalState | null>(null);
    const [isSavingIngress, setIsSavingIngress] = useState(false);
    const [savingIngressApplicationId, setSavingIngressApplicationId] = useState<string | null>(null);
    const canvasRef = useRef<HTMLDivElement | null>(null);
    const hasCanvasNodes = applications.length > 0 || ingresses.length > 0;

    const statusCounts = useMemo(
        () => ({
            running: applications.filter((application) => application.status === 'running').length,
            failed: applications.filter((application) => application.status === 'failed').length,
        }),
        [applications],
    );

    useEffect(() => {
        const settledResources = settleCanvasResources(initialApplications, caddyIngresses);

        setApplications(settledResources.applications);
        setIngresses(settledResources.ingresses);
        setConnections(initialResourceConnections);
        setSelectedNginxServerId((currentServerId) => currentServerId || nginxServers[0]?.id || '');
        setSelectedConnectionId(null);
        setSelectedApplicationId(null);
        centerOnCanvasNodes(settledResources.applications, settledResources.ingresses);
    }, [initialApplications, caddyIngresses, initialResourceConnections, selectedProjectUuid, selectedEnvironmentUuid]);

    useEffect(() => {
        if (!currentTeam) {
            return;
        }

        let isCancelled = false;
        let attempts = 0;
        const channelName = `team.${currentTeam.id}`;

        const interval = window.setInterval(() => {
            attempts += 1;

            if (!window.Echo) {
                if (attempts === 1) {
                    console.debug('Waiting for window.Echo before subscribing to canvas updates');
                }

                if (attempts >= 20) {
                    window.clearInterval(interval);
                }

                return;
            }

            window.clearInterval(interval);

            if (isCancelled) {
                return;
            }

            const channel = window.Echo.private(channelName);

            channel.subscribed?.(() => console.debug(`Subscribed to private-${channelName} for canvas updates`));
            channel.error?.((error) => console.error(`Subscription error on private-${channelName}`, error));
            channel.listen('.v5.canvas.resource.updated', (payload) => {
                const event = payload as V5CanvasResourceUpdatedEvent;

                if (event.application) {
                    setApplications((currentApplications) =>
                        currentApplications.map((application) =>
                            application.id === event.application?.id ? event.application : application,
                        ),
                    );
                }

                if (event.caddyIngress) {
                    setIngresses((currentIngresses) =>
                        currentIngresses.map((ingress) =>
                            ingress.id === event.caddyIngress?.id ? event.caddyIngress : ingress,
                        ),
                    );
                }
            });
        }, 500);

        return () => {
            isCancelled = true;
            window.clearInterval(interval);
            window.Echo?.leave?.(channelName) ?? window.Echo?.leaveChannel?.(`private-${channelName}`);
        };
    }, [currentTeam]);

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
    }, [selectedConnectionId]);

    function connectorPoint(endpoint: ConnectionEndpoint): { x: number; y: number } | null {
        const application = applications.find((candidate) => candidate.id === endpoint.applicationId);

        if (!application) {
            return null;
        }

        switch (endpoint.side) {
            case 'top':
                return { x: application.canvasX + APPLICATION_CARD_WIDTH / 2, y: application.canvasY };
            case 'right':
                return { x: application.canvasX + APPLICATION_CARD_WIDTH, y: application.canvasY + APPLICATION_CARD_HEIGHT / 2 };
            case 'bottom':
                return { x: application.canvasX + APPLICATION_CARD_WIDTH / 2, y: application.canvasY + APPLICATION_CARD_HEIGHT };
            case 'left':
                return { x: application.canvasX, y: application.canvasY + APPLICATION_CARD_HEIGHT / 2 };
        }
    }

    function applicationConnectorPoints(applicationId: string): Array<{ side: ConnectorSide; x: number; y: number }> {
        return CONNECTOR_SIDES.flatMap((side) => {
            const point = connectorPoint({ applicationId, side });

            return point ? [{ side, ...point }] : [];
        });
    }

    function shortestConnectionPoints(connection: CanvasConnection): { from: { x: number; y: number }; to: { x: number; y: number } } | null {
        const fromPoints = applicationConnectorPoints(connection.fromApplicationId);
        const toPoints = applicationConnectorPoints(connection.toApplicationId);
        let shortest: { from: { x: number; y: number }; to: { x: number; y: number }; distance: number } | null = null;

        for (const from of fromPoints) {
            for (const to of toPoints) {
                const distance = Math.hypot(from.x - to.x, from.y - to.y);

                if (!shortest || distance < shortest.distance) {
                    shortest = { from, to, distance };
                }
            }
        }

        return shortest ? { from: shortest.from, to: shortest.to } : null;
    }

    function connectionExists(fromApplicationId: string, toApplicationId: string): boolean {
        return connections.some(
            (connection) =>
                (connection.fromApplicationId === fromApplicationId && connection.toApplicationId === toApplicationId) ||
                (connection.fromApplicationId === toApplicationId && connection.toApplicationId === fromApplicationId),
        );
    }

    function applicationDirectionLabel(applicationId: string): string {
        const application = applications.find((candidate) => candidate.id === applicationId);

        if (!application) {
            return 'Unknown app';
        }

        return `${application.name} (${application.id.slice(0, 8)})`;
    }

    function connectionDirectionKey(fromApplicationId: string, toApplicationId: string): string {
        return `${fromApplicationId}->${toApplicationId}`;
    }

    function activeConnectionPorts(connection: CanvasConnection): string[] {
        return connection.portsByDirection[connectionDirectionKey(connection.fromApplicationId, connection.toApplicationId)] ?? [];
    }

function normalizeConnection(connection: V5ResourceConnection): CanvasConnection {
        return {
            ...connection,
            applicationIds: [connection.applicationIds[0], connection.applicationIds[1]],
            portsByDirection: connection.portsByDirection ?? {},
        };
    }

    async function persistNewConnection(fromApplicationId: string, toApplicationId: string): Promise<void> {
        setNotice(null);


        try {
            const response = await fetch('/v5/resource-connections', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                },
                body: JSON.stringify({
                    resource_one: { type: 'application', id: Number(fromApplicationId) },
                    resource_two: { type: 'application', id: Number(toApplicationId) },
                }),
            });
            const payload = (await response.json()) as { connection?: V5ResourceConnection; message?: string };

            if (!response.ok || !payload.connection) {
                setNotice(payload.message ?? 'Could not save resource connection.');

                return;
            }

            const nextConnection = normalizeConnection(payload.connection);

            setConnections((currentConnections) => {
                const withoutDuplicate = currentConnections.filter((connection) => connection.id !== nextConnection.id);

                return [...withoutDuplicate, nextConnection];
            });
            setSelectedConnectionId(nextConnection.id);
        } catch (error) {
            setNotice(error instanceof Error ? error.message : 'Could not save resource connection.');
        }
    }

    async function persistConnectionPorts(connection: CanvasConnection): Promise<void> {
        const portsByDirection = Object.fromEntries(
            Object.entries(connection.portsByDirection).map(([direction, ports]) => [
                direction,
                ports.map((port) => Number(port)).filter((port) => Number.isInteger(port)),
            ]),
        );


        try {
            const response = await fetch(`/v5/resource-connections/${connection.id}`, {
                method: 'PATCH',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                },
                body: JSON.stringify({ ports_by_direction: portsByDirection }),
            });
            const payload = (await response.json()) as { connection?: V5ResourceConnection; message?: string };

            if (!response.ok || !payload.connection) {
                setNotice(payload.message ?? 'Could not save allowed ports.');

                return;
            }

            const nextConnection = normalizeConnection(payload.connection);
            setConnections((currentConnections) =>
                currentConnections.map((currentConnection) =>
                    currentConnection.id === nextConnection.id ? nextConnection : currentConnection,
                ),
            );
        } catch (error) {
            setNotice(error instanceof Error ? error.message : 'Could not save allowed ports.');
        }
    }

    async function deletePersistedConnection(connectionId: string): Promise<void> {

        try {
            const response = await fetch(`/v5/resource-connections/${connectionId}`, {
                method: 'DELETE',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                },
            });

            if (!response.ok) {
                setNotice('Could not delete resource connection.');
            }
        } catch (error) {
            setNotice(error instanceof Error ? error.message : 'Could not delete resource connection.');
        }
    }

        function deleteConnection(connectionId: string): void {
        setConnections((currentConnections) => currentConnections.filter((connection) => connection.id !== connectionId));
        setSelectedConnectionId(null);
        void deletePersistedConnection(connectionId);
    }

    function updateConnectionDirection(connectionId: string, fromApplicationId: string, toApplicationId: string): void {
        setConnections((currentConnections) =>
            currentConnections.map((connection) =>
                connection.id === connectionId
                    ? {
                          ...connection,
                          fromApplicationId,
                          toApplicationId,
                      }
                    : connection,
            ),
        );
    }

    function addConnectionPort(connectionId: string): void {
        const port = connectionPortInput[connectionId]?.trim();
        const portNumber = Number(port);

        if (!port || !Number.isInteger(portNumber) || portNumber < 1 || portNumber > 65535) {
            return;
        }

        const connection = connections.find((candidate) => candidate.id === connectionId);

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

        setConnections((currentConnections) =>
            currentConnections.map((currentConnection) =>
                currentConnection.id === updatedConnection.id ? updatedConnection : currentConnection,
            ),
        );
        void persistConnectionPorts(updatedConnection);
        setConnectionPortInput((currentInputs) => ({ ...currentInputs, [connectionId]: '' }));
    }

    function removeConnectionPort(connectionId: string, port: string): void {
        const connection = connections.find((candidate) => candidate.id === connectionId);

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

        setConnections((currentConnections) =>
            currentConnections.map((currentConnection) =>
                currentConnection.id === updatedConnection.id ? updatedConnection : currentConnection,
            ),
        );
        void persistConnectionPorts(updatedConnection);
    }

    function settleCanvasResources(
        nextApplications: V5Application[],
        nextIngresses: V5CaddyIngress[],
    ): { applications: V5Application[]; ingresses: V5CaddyIngress[] } {
        const settledNodes = resolveCanvasNodeLayout(
            [
                ...nextApplications.map((application) => ({
                    id: `application-${application.id}`,
                    x: application.canvasX,
                    y: application.canvasY,
                    width: APPLICATION_CARD_WIDTH,
                    height: APPLICATION_CARD_HEIGHT,
                })),
                ...nextIngresses.map((ingress) => ({
                    id: `ingress-${ingress.id}`,
                    x: ingress.canvasX,
                    y: ingress.canvasY,
                    width: APPLICATION_CARD_WIDTH,
                    height: APPLICATION_CARD_HEIGHT,
                })),
            ],
            CANVAS_CARD_GAP,
        );
        const positionsById = new Map(settledNodes.map((node) => [node.id, node]));

        return {
            applications: nextApplications.map((application) => {
                const position = positionsById.get(`application-${application.id}`);

                return position ? { ...application, canvasX: position.x, canvasY: position.y } : application;
            }),
            ingresses: nextIngresses.map((ingress) => {
                const position = positionsById.get(`ingress-${ingress.id}`);

                return position ? { ...ingress, canvasX: position.x, canvasY: position.y } : ingress;
            }),
        };
    }

    function canvasCollisionNodes(): CanvasNodeBounds[] {
        return [
            ...applications.map((application) => ({
                id: `application-${application.id}`,
                x: application.canvasX,
                y: application.canvasY,
                width: APPLICATION_CARD_WIDTH,
                height: APPLICATION_CARD_HEIGHT,
            })),
            ...ingresses.map((ingress) => ({
                id: `ingress-${ingress.id}`,
                x: ingress.canvasX,
                y: ingress.canvasY,
                width: APPLICATION_CARD_WIDTH,
                height: APPLICATION_CARD_HEIGHT,
            })),
        ];
    }

    function resolveApplicationPosition(application: V5Application): V5Application {
        const position = resolveCanvasNodePosition(
            {
                id: `application-${application.id}`,
                x: application.canvasX,
                y: application.canvasY,
                width: APPLICATION_CARD_WIDTH,
                height: APPLICATION_CARD_HEIGHT,
            },
            canvasCollisionNodes(),
            CANVAS_CARD_GAP,
        );

        return { ...application, canvasX: position.x, canvasY: position.y };
    }

    function resolveIngressPosition(ingress: V5CaddyIngress): V5CaddyIngress {
        const position = resolveCanvasNodePosition(
            {
                id: `ingress-${ingress.id}`,
                x: ingress.canvasX,
                y: ingress.canvasY,
                width: APPLICATION_CARD_WIDTH,
                height: APPLICATION_CARD_HEIGHT,
            },
            canvasCollisionNodes(),
            CANVAS_CARD_GAP,
        );

        return { ...ingress, canvasX: position.x, canvasY: position.y };
    }

    function canvasPointFromPointer(event: PointerEvent): { x: number; y: number } {
        const rect = canvasRef.current?.getBoundingClientRect();

        if (!rect) {
            return { x: 0, y: 0 };
        }

        return {
            x: (event.clientX - rect.left - viewport.x) / viewport.zoom,
            y: (event.clientY - rect.top - viewport.y) / viewport.zoom,
        };
    }

    async function removeApplication(application: V5Application): Promise<void> {
        setNotice(null);


        try {
            const response = await fetch(`/v5/applications/${application.id}`, {
                method: 'DELETE',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                },
            });

            if (!response.ok) {
                setNotice('Could not delete application.');

                return;
            }

            setApplications((currentApplications) =>
                currentApplications.filter((candidate) => candidate.id !== application.id),
            );
            setConnections((currentConnections) =>
                currentConnections.filter(
                    (connection) =>
                        connection.fromApplicationId !== application.id && connection.toApplicationId !== application.id,
                ),
            );
        } catch (error) {
            setNotice(error instanceof Error ? error.message : 'Could not delete application.');
        }
    }

    function openApplicationIngressModal(application: V5Application): void {
        setNotice(null);

        if (!application.serverIngressEnabled) {
            setNotice('Enable ingress on the server before enabling app ingress.');

            return;
        }

        setIngressModal({
            application,
            domains: application.domains.join(', '),
            internalPort: application.internalPort ? String(application.internalPort) : '',
            error: null,
        });
    }

    async function disableApplicationIngress(application: V5Application): Promise<void> {
        await saveApplicationIngress(application, false, application.domains, application.internalPort);
    }

    async function submitApplicationIngress(): Promise<void> {
        if (!ingressModal) {
            return;
        }

        const domains = ingressModal.domains
            .split(',')
            .map((domain) => domain.trim().toLowerCase())
            .filter(Boolean);
        const internalPort = Number(ingressModal.internalPort);
        const invalidDomain = domains.find((domain) => !isValidDomain(domain));

        if (domains.length === 0) {
            setIngressModal({ ...ingressModal, error: 'Add at least one valid domain.' });

            return;
        }

        if (invalidDomain) {
            setIngressModal({ ...ingressModal, error: `${invalidDomain} is not a valid domain.` });

            return;
        }

        if (!Number.isInteger(internalPort) || internalPort < 1 || internalPort > 65535) {
            setIngressModal({ ...ingressModal, error: 'Choose a valid internal port between 1 and 65535.' });

            return;
        }

        await saveApplicationIngress(ingressModal.application, true, [...new Set(domains)], internalPort);
    }

    async function saveApplicationIngress(
        application: V5Application,
        enabled: boolean,
        domains: string[],
        internalPort: number | null,
    ): Promise<void> {
        setNotice(null);
        setIsSavingIngress(true);
        setSavingIngressApplicationId(application.id);

        try {
            const response = await fetch(`/v5/applications/${application.id}/ingress`, {
                method: 'PATCH',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                },
                body: JSON.stringify({
                    ingress_enabled: enabled,
                    internal_port: internalPort,
                    domains,
                }),
            });

            if (!response.ok) {
                const payload = (await response.json().catch(() => null)) as { message?: string } | null;
                const message = payload?.message ?? 'Could not update application ingress.';

                if (ingressModal) {
                    setIngressModal({ ...ingressModal, error: message });
                } else {
                    setNotice(message);
                }

                return;
            }

            const payload = (await response.json()) as { application: V5Application };
            setApplications((currentApplications) =>
                currentApplications.map((candidate) =>
                    candidate.id === payload.application.id ? payload.application : candidate,
                ),
            );
            setIngressModal(null);
        } catch (error) {
            const message = error instanceof Error ? error.message : 'Could not update application ingress.';

            if (ingressModal) {
                setIngressModal({ ...ingressModal, error: message });
            } else {
                setNotice(message);
            }
        } finally {
            setIsSavingIngress(false);
            setSavingIngressApplicationId(null);
        }
    }

    function isValidDomain(domain: string): boolean {
        if (domain.length < 1 || domain.length > 253 || domain.startsWith('.') || domain.endsWith('.')) {
            return false;
        }

        return domain.split('.').every((label) => /^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/.test(label));
    }

    function renderIngressButton(application: V5Application) {
        const isDisabled = !application.ingressEnabled && !application.serverIngressEnabled;
        const isApplicationIngressSaving = savingIngressApplicationId === application.id;
        const button = (
            <button
                type="button"
                onPointerDown={(event) => event.stopPropagation()}
                disabled={isDisabled || isApplicationIngressSaving}
                onClick={(event) => {
                    event.stopPropagation();
                    application.ingressEnabled
                        ? void disableApplicationIngress(application)
                        : openApplicationIngressModal(application);
                }}
                className="rounded-sm border border-border px-2 py-1 text-[0.625rem] font-semibold uppercase tracking-wide text-foreground transition hover:bg-muted disabled:cursor-not-allowed disabled:opacity-50"
            >
                {isApplicationIngressSaving ? 'Saving...' : application.ingressEnabled ? 'Disable' : 'Enable'}
            </button>
        );

        if (!isDisabled) {
            return button;
        }

        return (
            <Tooltip>
                <TooltipTrigger render={<span className="inline-flex" />}>{button}</TooltipTrigger>
                <TooltipContent side="top">
                    <p>You need to enable ingress in server settings first.</p>
                </TooltipContent>
            </Tooltip>
        );
    }

    async function addNginx(): Promise<void> {
        setIsCreating(true);
        setNotice(null);


        try {
            const response = await fetch('/v5/applications/nginx', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                },
                body: JSON.stringify({
                    server_id: selectedNginxServerId || null,
                }),
            });
            const payload = (await response.json()) as { application?: V5Application; message?: string };

            if (payload.application) {
                const settledResources = settleCanvasResources([...applications, payload.application], ingresses);
                const settledApplication = settledResources.applications.find(
                    (application) => application.id === payload.application?.id,
                );

                setApplications(settledResources.applications);
                setIngresses(settledResources.ingresses);
                centerOnCanvasNodes(settledResources.applications, settledResources.ingresses);

                if (
                    settledApplication &&
                    (settledApplication.canvasX !== payload.application.canvasX ||
                        settledApplication.canvasY !== payload.application.canvasY)
                ) {
                    void persistApplicationPosition(settledApplication);
                }
            }

            if (!response.ok) {
                setNotice(payload.application?.statusMessage ?? payload.message ?? 'Could not deploy nginx.');
            }
        } catch (error) {
            setNotice(error instanceof Error ? error.message : 'Could not deploy nginx.');
        } finally {
            setIsCreating(false);
        }
    }

    async function refreshApplications(): Promise<void> {
        setIsRefreshing(true);
        setNotice(null);


        try {
            const response = await fetch('/v5/applications/refresh', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                },
            });
            const payload = (await response.json()) as {
                applications?: V5Application[];
                errors?: string[];
                message?: string;
            };

            if (payload.applications) {
                const settledResources = settleCanvasResources(payload.applications, ingresses);

                setApplications(settledResources.applications);
                setIngresses(settledResources.ingresses);
            }

            if (!response.ok) {
                setNotice(payload.message ?? 'Could not refresh application state.');
            } else if (payload.errors && payload.errors.length > 0) {
                setNotice(payload.errors[0] ?? 'Could not refresh all application state.');
            }
        } catch (error) {
            setNotice(error instanceof Error ? error.message : 'Could not refresh application state.');
        } finally {
            setIsRefreshing(false);
        }
    }

    function centerOnCanvasNodes(
        nextApplications = applications,
        nextCaddyIngresses: V5CaddyIngress[] = ingresses,
    ): void {
        const canvas = canvasRef.current;
        const nodes = [...nextApplications, ...nextCaddyIngresses];

        if (!canvas || nodes.length === 0) {
            setViewport((currentViewport) => ({ x: 0, y: 0, zoom: currentViewport.zoom }));

            return;
        }

        const bounds = nodes.reduce(
            (currentBounds, node) => ({
                minX: Math.min(currentBounds.minX, node.canvasX),
                maxX: Math.max(currentBounds.maxX, node.canvasX),
                minY: Math.min(currentBounds.minY, node.canvasY),
                maxY: Math.max(currentBounds.maxY, node.canvasY),
            }),
            {
                minX: nodes[0]?.canvasX ?? 0,
                maxX: nodes[0]?.canvasX ?? 0,
                minY: nodes[0]?.canvasY ?? 0,
                maxY: nodes[0]?.canvasY ?? 0,
            },
        );
        const centerX = (bounds.minX + bounds.maxX) / 2;
        const centerY = (bounds.minY + bounds.maxY) / 2;
        const rect = canvas.getBoundingClientRect();

        setViewport((currentViewport) => ({
            x: rect.width / 2 - (centerX + APPLICATION_CARD_WIDTH / 2) * currentViewport.zoom,
            y: rect.height / 2 - (centerY + APPLICATION_CARD_HEIGHT / 2) * currentViewport.zoom,
            zoom: currentViewport.zoom,
        }));
    }

    function startPan(event: PointerEvent<HTMLDivElement>): void {
        if (event.target !== event.currentTarget) {
            return;
        }

        event.currentTarget.setPointerCapture(event.pointerId);
        setPointerState({
            type: 'pan',
            pointerId: event.pointerId,
            startClientX: event.clientX,
            startClientY: event.clientY,
            startViewport: viewport,
        });
    }

    function startApplicationDrag(event: PointerEvent<HTMLDivElement>, application: V5Application): void {
        event.stopPropagation();
        event.currentTarget.setPointerCapture(event.pointerId);
        setSelectedConnectionId(null);
        setSelectedApplicationId(application.id);
        setPointerState({
            type: 'app',
            pointerId: event.pointerId,
            applicationId: application.id,
            startClientX: event.clientX,
            startClientY: event.clientY,
            startX: application.canvasX,
            startY: application.canvasY,
        });
    }

    function startIngressDrag(event: PointerEvent<HTMLDivElement>, ingress: V5CaddyIngress): void {
        event.stopPropagation();
        event.currentTarget.setPointerCapture(event.pointerId);
        setPointerState({
            type: 'ingress',
            pointerId: event.pointerId,
            ingressId: ingress.id,
            startClientX: event.clientX,
            startClientY: event.clientY,
            startX: ingress.canvasX,
            startY: ingress.canvasY,
        });
    }

    function startConnectionDrag(
        event: PointerEvent<HTMLButtonElement>,
        applicationId: string,
        side: ConnectorSide,
    ): void {
        event.stopPropagation();

        const from = { applicationId, side };
        const startPoint = connectorPoint(from) ?? canvasPointFromPointer(event);

        setDraftConnection({
            from,
            toX: startPoint.x,
            toY: startPoint.y,
        });
        setPointerState({
            type: 'connection',
            pointerId: event.pointerId,
            from,
        });
    }

    function selectConnection(event: MouseEvent<SVGLineElement>, connectionId: string): void {
        event.stopPropagation();
        setSelectedConnectionId(connectionId);
        setSelectedApplicationId(null);
    }

    function clearCanvasSelection(event: MouseEvent<HTMLDivElement>): void {
        if (event.target !== event.currentTarget) {
            return;
        }

        setSelectedConnectionId(null);
        setSelectedApplicationId(null);
    }

    function connectionTargetFromPointer(event: PointerEvent<HTMLDivElement>): HTMLElement | null {
        const pointerTarget = document.elementFromPoint(event.clientX, event.clientY) as HTMLElement | null;

        // Touch browsers may keep pointer-captured mobile drags targeted at the origin connector.
        return pointerTarget?.closest<HTMLElement>('[data-application-card]') ?? null;
    }

    function movePointer(event: PointerEvent<HTMLDivElement>): void {
        if (!pointerState || pointerState.pointerId !== event.pointerId) {
            return;
        }

        if (pointerState.type === 'connection') {
            const point = canvasPointFromPointer(event);

            setDraftConnection({
                from: pointerState.from,
                toX: point.x,
                toY: point.y,
            });

            return;
        }

        const deltaX = event.clientX - pointerState.startClientX;
        const deltaY = event.clientY - pointerState.startClientY;

        if (pointerState.type === 'pan') {
            setViewport({
                x: pointerState.startViewport.x + deltaX,
                y: pointerState.startViewport.y + deltaY,
                zoom: pointerState.startViewport.zoom,
            });

            return;
        }

        if (pointerState.type === 'app') {
            setApplications((currentApplications) =>
                currentApplications.map((application) =>
                    application.id === pointerState.applicationId
                        ? {
                              ...application,
                              canvasX: Math.round(pointerState.startX + deltaX / viewport.zoom),
                              canvasY: Math.round(pointerState.startY + deltaY / viewport.zoom),
                          }
                        : application,
                ),
            );

            return;
        }

        setIngresses((currentIngresses) =>
            currentIngresses.map((ingress) =>
                ingress.id === pointerState.ingressId
                    ? {
                          ...ingress,
                          canvasX: Math.round(pointerState.startX + deltaX / viewport.zoom),
                          canvasY: Math.round(pointerState.startY + deltaY / viewport.zoom),
                      }
                    : ingress,
            ),
        );
    }

    function clampCanvasZoom(zoom: number): number {
        return Math.min(MAX_CANVAS_ZOOM, Math.max(MIN_CANVAS_ZOOM, zoom));
    }

    function zoomCanvas(direction: 1 | -1, step = CANVAS_ZOOM_STEP, origin?: { x: number; y: number }): void {
        const rect = canvasRef.current?.getBoundingClientRect();

        setViewport((currentViewport) => {
            const nextZoom = clampCanvasZoom(currentViewport.zoom + direction * step);

            if (!rect || nextZoom === currentViewport.zoom) {
                return currentViewport;
            }

            const originX = origin?.x ?? rect.width / 2;
            const originY = origin?.y ?? rect.height / 2;
            const canvasX = (originX - currentViewport.x) / currentViewport.zoom;
            const canvasY = (originY - currentViewport.y) / currentViewport.zoom;

            return {
                x: originX - canvasX * nextZoom,
                y: originY - canvasY * nextZoom,
                zoom: nextZoom,
            };
        });
    }

    function handleCanvasWheel(event: WheelEvent<HTMLDivElement>): void {
        if (!event.ctrlKey) {
            return;
        }

        const rect = event.currentTarget.getBoundingClientRect();

        event.preventDefault();
        zoomCanvas(event.deltaY < 0 ? 1 : -1, PINCH_CANVAS_ZOOM_STEP, {
            x: event.clientX - rect.left,
            y: event.clientY - rect.top,
        });
    }

    function stopPointer(event: PointerEvent<HTMLDivElement>): void {
        if (!pointerState || pointerState.pointerId !== event.pointerId) {
            return;
        }

        if (pointerState.type === 'connection') {
            const target = connectionTargetFromPointer(event);
            const targetApplicationId = target?.dataset.applicationId;

            if (
                targetApplicationId &&
                targetApplicationId !== pointerState.from.applicationId &&
                !connectionExists(pointerState.from.applicationId, targetApplicationId)
            ) {
                void persistNewConnection(pointerState.from.applicationId, targetApplicationId);
            }

            setDraftConnection(null);
            setPointerState(null);

            return;
        }

        const deltaX = event.clientX - pointerState.startClientX;
        const deltaY = event.clientY - pointerState.startClientY;

        if (pointerState.type === 'app') {
            const application = applications.find((candidate) => candidate.id === pointerState.applicationId);

            if (application) {
                const updatedApplication = resolveApplicationPosition({
                    ...application,
                    canvasX: Math.round(pointerState.startX + deltaX / viewport.zoom),
                    canvasY: Math.round(pointerState.startY + deltaY / viewport.zoom),
                });

                setApplications((currentApplications) =>
                    currentApplications.map((candidate) => (candidate.id === updatedApplication.id ? updatedApplication : candidate)),
                );
                void persistApplicationPosition(updatedApplication);
            }
        }

        if (pointerState.type === 'ingress') {
            const ingress = ingresses.find((candidate) => candidate.id === pointerState.ingressId);

            if (ingress) {
                const updatedIngress = resolveIngressPosition({
                    ...ingress,
                    canvasX: Math.round(pointerState.startX + deltaX / viewport.zoom),
                    canvasY: Math.round(pointerState.startY + deltaY / viewport.zoom),
                });

                setIngresses((currentIngresses) =>
                    currentIngresses.map((candidate) => (candidate.id === updatedIngress.id ? updatedIngress : candidate)),
                );
                void persistCaddyIngressPosition(updatedIngress);
            }
        }

        setPointerState(null);
    }

    return (
        <TooltipProvider>
            <Head title="Dashboard" />

            <div className="h-dvh overflow-hidden bg-background text-foreground">
                <AppNavbar
                    flux={flux}
                    projects={projects}
                    selectedProjectUuid={selectedProjectUuid}
                    selectedEnvironmentUuid={selectedEnvironmentUuid}
                />

                <main className="relative h-full min-h-0 overflow-hidden pt-16">
                    <div className="absolute left-4 top-20 z-30 flex max-w-[calc(100%-2rem)] flex-wrap items-center gap-2 rounded-xl border border-border bg-background/95 p-2 shadow-lg backdrop-blur">
                        <select
                            aria-label="Select nginx server"
                            value={selectedNginxServerId}
                            onChange={(event) => setSelectedNginxServerId(event.target.value)}
                            disabled={isCreating || nginxServers.length === 0}
                            className="rounded-lg border border-border bg-background px-3 py-2 text-sm font-medium text-foreground transition disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            {nginxServers.length === 0 ? (
                                <option value="">No servers available</option>
                            ) : (
                                nginxServers.map((server) => (
                                    <option key={server.id} value={server.id}>
                                        {server.name} ({server.host})
                                    </option>
                                ))
                            )}
                        </select>
                        <button
                            type="button"
                            onClick={() => void addNginx()}
                            disabled={isCreating || nginxServers.length === 0}
                            className="rounded-lg bg-warning px-3 py-2 text-sm font-semibold text-black transition hover:bg-warning/90 disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            {isCreating ? 'Adding nginx…' : 'Add nginx'}
                        </button>
                        <button
                            type="button"
                            onClick={() => centerOnCanvasNodes()}
                            className="rounded-lg border border-border px-3 py-2 text-sm font-medium text-foreground transition hover:bg-muted"
                        >
                            Center
                        </button>
                        <div className="flex items-center overflow-hidden rounded-lg border border-border">
                            <button
                                type="button"
                                aria-label="Zoom out"
                                onClick={() => zoomCanvas(-1)}
                                className="px-3 py-2 text-sm font-semibold text-foreground transition hover:bg-muted disabled:cursor-not-allowed disabled:opacity-50"
                                disabled={viewport.zoom <= MIN_CANVAS_ZOOM}
                            >
                                −
                            </button>
                            <span className="min-w-14 border-x border-border px-2 py-2 text-center text-xs font-medium text-muted-foreground">
                                {Math.round(viewport.zoom * 100)}%
                            </span>
                            <button
                                type="button"
                                aria-label="Zoom in"
                                onClick={() => zoomCanvas(1)}
                                className="px-3 py-2 text-sm font-semibold text-foreground transition hover:bg-muted disabled:cursor-not-allowed disabled:opacity-50"
                                disabled={viewport.zoom >= MAX_CANVAS_ZOOM}
                            >
                                +
                            </button>
                        </div>
                        <button
                            type="button"
                            onClick={() => void refreshApplications()}
                            disabled={isRefreshing}
                            className="rounded-lg border border-border px-3 py-2 text-sm font-medium text-foreground transition hover:bg-muted disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            {isRefreshing ? 'Refreshing…' : 'Refresh state'}
                        </button>
                        <div className="hidden items-center gap-2 px-2 text-xs text-muted-foreground sm:flex">
                            <span>{applications.length} apps</span>
                            <span>•</span>
                            <span>{statusCounts.running} running</span>
                            {statusCounts.failed > 0 && (
                                <>
                                    <span>•</span>
                                    <span className="text-destructive">{statusCounts.failed} failed</span>
                                </>
                            )}
                        </div>
                    </div>

                    {notice && (
                        <div className="absolute right-4 top-20 z-30 max-w-sm rounded-lg border border-destructive/40 bg-destructive/10 p-3 text-sm text-destructive shadow-lg">
                            {notice}
                        </div>
                    )}

                    <div
                        ref={canvasRef}
                        className="relative size-full touch-none overflow-hidden bg-[radial-gradient(circle_at_1px_1px,var(--border)_1px,transparent_0)] [background-size:32px_32px]"
                        onPointerDown={startPan}
                        onClick={clearCanvasSelection}
                        onPointerMove={movePointer}
                        onPointerUp={stopPointer}
                        onPointerCancel={stopPointer}
                        onWheel={handleCanvasWheel}
                    >
                        {!hasCanvasNodes && (
                            <section className="pointer-events-none absolute left-1/2 top-1/2 flex w-[min(32rem,calc(100%-2rem))] -translate-x-1/2 -translate-y-1/2 flex-col items-center justify-center gap-3 rounded-lg border border-dashed border-border bg-card/90 px-8 py-16 text-center shadow-sm">
                                <p className="text-sm font-medium text-foreground">No applications on this canvas yet.</p>
                                <p className="max-w-md text-sm text-muted-foreground">
                                    Click Add nginx to deploy a test container on one of your v5 servers.
                                </p>
                            </section>
                        )}

                        <div
                            className="absolute left-0 top-0"
                            style={{
                                transform: `translate3d(${viewport.x}px, ${viewport.y}px, 0) scale(${viewport.zoom})`,
                                transformOrigin: '0 0',
                            }}
                        >
                            {ingresses.map((ingress) => (
                                <div
                                    key={`caddy-ingress-${ingress.id}`}
                                    className="absolute w-80 select-none overflow-hidden rounded-xl border border-warning/40 bg-card p-4 shadow-xl transition-shadow hover:shadow-2xl"
                                    style={{
                                        transform: `translate3d(${ingress.canvasX}px, ${ingress.canvasY}px, 0)`,
                                    }}
                                    onPointerDown={(event) => startIngressDrag(event, ingress)}
                                >
                                    <div className="flex items-start justify-between gap-3">
                                        <div className="min-w-0">
                                            <div className="truncate text-sm font-semibold text-foreground">Caddy ingress</div>
                                            <div className="mt-1 truncate text-xs text-muted-foreground">{ingress.name}</div>
                                        </div>
                                        <span
                                            className={cn(
                                                'shrink-0 rounded-full px-2 py-1 text-[0.625rem] font-semibold uppercase tracking-wide',
                                                ingress.status === 'running' && 'bg-emerald-500/15 text-emerald-400',
                                                ingress.status === 'creating' && 'bg-warning/15 text-warning',
                                                ingress.status === 'failed' && 'bg-destructive/15 text-destructive',
                                                ingress.status === 'exited' && 'bg-destructive/15 text-destructive',
                                            )}
                                        >
                                            {ingress.status}
                                        </span>
                                    </div>

                                    <dl className="mt-4 grid gap-2 text-xs">
                                        <div className="grid grid-cols-[auto_minmax(0,1fr)] gap-3">
                                            <dt className="shrink-0 text-muted-foreground">Server</dt>
                                            <dd className="truncate text-right font-medium text-foreground">{ingress.name}</dd>
                                        </div>
                                        <div className="grid grid-cols-[auto_minmax(0,1fr)] gap-3">
                                            <dt className="shrink-0 text-muted-foreground">Host</dt>
                                            <dd className="truncate text-right font-mono text-[0.6875rem] text-foreground">
                                                {ingress.host}
                                            </dd>
                                        </div>
                                    </dl>
                                </div>
                            ))}

                            <svg className="pointer-events-none absolute inset-0 overflow-visible">
                                <defs>
                                    <marker
                                        id="dashboard-connection-arrow"
                                        viewBox="0 0 10 10"
                                        refX="9"
                                        refY="5"
                                        markerWidth="16"
                                        markerHeight="16"
                                        orient="auto"
                                        markerUnits="userSpaceOnUse"
                                    >
                                        <path d="M 0 0 L 10 5 L 0 10 z" fill="var(--destructive)" />
                                    </marker>
                                </defs>
                                {connections.map((connection) => {
                                    const points = shortestConnectionPoints(connection);

                                    if (!points) {
                                        return null;
                                    }

                                    return (
                                        <g key={connection.id}>
                                            <line
                                                x1={points.from.x}
                                                y1={points.from.y}
                                                x2={points.to.x}
                                                y2={points.to.y}
                                                aria-label="Select connection"
                                                className="pointer-events-auto cursor-pointer"
                                                stroke="transparent"
                                                strokeWidth={12}
                                                strokeLinecap="round"
                                                onPointerDown={(event) => event.stopPropagation()}
                                                onClick={(event) => selectConnection(event, connection.id)}
                                            />
                                            <line
                                                x1={points.from.x}
                                                y1={points.from.y}
                                                x2={points.to.x}
                                                y2={points.to.y}
                                                className={cn(
                                                    'pointer-events-none',
                                                    selectedConnectionId === connection.id ? 'stroke-destructive' : 'stroke-warning',
                                                )}
                                                strokeWidth={selectedConnectionId === connection.id ? 4 : 2}
                                                strokeDasharray="6 6"
                                                strokeLinecap="round"
                                                markerEnd={selectedConnectionId === connection.id ? 'url(#dashboard-connection-arrow)' : undefined}
                                            />
                                        </g>
                                    );
                                })}
                                {draftConnection &&
                                    (() => {
                                        const from = connectorPoint(draftConnection.from);

                                        if (!from) {
                                            return null;
                                        }

                                        return (
                                            <line
                                                x1={from.x}
                                                y1={from.y}
                                                x2={draftConnection.toX}
                                                y2={draftConnection.toY}
                                                className="stroke-warning/70"
                                                strokeWidth={2}
                                                strokeDasharray="6 6"
                                                strokeLinecap="round"
                                            />
                                        );
                                    })()}
                            </svg>

                            {connections.map((connection) => {
                                if (connection.id !== selectedConnectionId) {
                                    return null;
                                }

                                const points = shortestConnectionPoints(connection);

                                if (!points) {
                                    return null;
                                }

                                const activePorts = activeConnectionPorts(connection);
                                const firstApplicationId = connection.applicationIds[0];
                                const secondApplicationId = connection.applicationIds[1];
                                const isForwardDirection =
                                    connection.fromApplicationId === firstApplicationId &&
                                    connection.toApplicationId === secondApplicationId;

                                return (
                                    <div
                                        key={`connection-controls-${connection.id}`}
                                        className="absolute z-20 flex w-72 -translate-x-1/2 -translate-y-1/2 flex-col gap-3 rounded-md border border-border bg-card p-3 shadow-lg"
                                        style={{
                                            left: (points.from.x + points.to.x) / 2,
                                            top: (points.from.y + points.to.y) / 2,
                                        }}
                                        onPointerDown={(event) => event.stopPropagation()}
                                        onClick={(event) => event.stopPropagation()}
                                    >
                                        <div className="space-y-2">
                                            <div className="text-[0.625rem] font-semibold uppercase tracking-wide text-muted-foreground">
                                                Firewall
                                            </div>
                                            <div className="grid gap-1">
                                                <button
                                                    type="button"
                                                    onClick={() =>
                                                        updateConnectionDirection(
                                                            connection.id,
                                                            firstApplicationId,
                                                            secondApplicationId,
                                                        )
                                                    }
                                                    className={cn(
                                                        'rounded-sm border px-2 py-1 text-left text-xs transition',
                                                        isForwardDirection
                                                            ? 'border-warning/40 bg-warning/10 text-foreground hover:bg-warning/20'
                                                            : 'border-border text-muted-foreground hover:bg-muted hover:text-foreground',
                                                    )}
                                                >
                                                    {applicationDirectionLabel(firstApplicationId)} →{' '}
                                                    {applicationDirectionLabel(secondApplicationId)}
                                                </button>
                                                <button
                                                    type="button"
                                                    onClick={() =>
                                                        updateConnectionDirection(
                                                            connection.id,
                                                            secondApplicationId,
                                                            firstApplicationId,
                                                        )
                                                    }
                                                    className={cn(
                                                        'rounded-sm border px-2 py-1 text-left text-xs transition',
                                                        !isForwardDirection
                                                            ? 'border-warning/40 bg-warning/10 text-foreground hover:bg-warning/20'
                                                            : 'border-border text-muted-foreground hover:bg-muted hover:text-foreground',
                                                    )}
                                                >
                                                    {applicationDirectionLabel(secondApplicationId)} →{' '}
                                                    {applicationDirectionLabel(firstApplicationId)}
                                                </button>
                                            </div>
                                        </div>

                                        <div className="space-y-2">
                                            <div className="text-[0.625rem] font-semibold uppercase tracking-wide text-muted-foreground">
                                                Allowed ports
                                            </div>
                                            <div className="flex flex-wrap gap-1">
                                                {activePorts.length === 0 && (
                                                    <span className="text-xs text-muted-foreground">No ports yet.</span>
                                                )}
                                                {activePorts.map((port) => (
                                                    <button
                                                        key={port}
                                                        type="button"
                                                        onClick={() => removeConnectionPort(connection.id, port)}
                                                        className="rounded-full border border-border bg-muted px-2 py-1 text-[0.625rem] font-medium text-foreground transition hover:border-destructive/40 hover:text-destructive"
                                                    >
                                                        {port} ×
                                                    </button>
                                                ))}
                                            </div>
                                            <div className="flex gap-1">
                                                <input
                                                    type="number"
                                                    min={1}
                                                    max={65535}
                                                    placeholder="Port"
                                                    value={connectionPortInput[connection.id] ?? ''}
                                                    onChange={(event) =>
                                                        setConnectionPortInput((currentInputs) => ({
                                                            ...currentInputs,
                                                            [connection.id]: event.target.value,
                                                        }))
                                                    }
                                                    onKeyDown={(event) => {
                                                        if (event.key === 'Enter') {
                                                            addConnectionPort(connection.id);
                                                        }
                                                    }}
                                                    className="min-w-0 flex-1 rounded-sm border border-border bg-background px-2 py-1 text-xs text-foreground outline-none transition focus:border-warning"
                                                />
                                                <button
                                                    type="button"
                                                    onClick={() => addConnectionPort(connection.id)}
                                                    className="rounded-sm border border-border px-2 py-1 text-xs font-medium text-foreground transition hover:bg-muted"
                                                >
                                                    Add
                                                </button>
                                            </div>
                                        </div>

                                        <button
                                            type="button"
                                            aria-label="Delete connection"
                                            onClick={() => deleteConnection(connection.id)}
                                            className="rounded-sm border border-destructive/40 px-2 py-1 text-[0.625rem] font-semibold uppercase tracking-wide text-destructive transition hover:bg-destructive/10"
                                        >
                                            Delete
                                        </button>
                                    </div>
                                );
                            })}

                            {applications.map((application) => (
                                <div
                                    key={application.id}
                                    data-application-card="application-card"
                                    data-application-id={application.id}
                                    className="group/application absolute min-h-[8.5rem] w-80 select-none overflow-visible rounded-xl border border-border bg-card p-4 shadow-xl transition-shadow hover:shadow-2xl"
                                    style={{
                                        transform: `translate3d(${application.canvasX}px, ${application.canvasY}px, 0)`,
                                    }}
                                    onPointerDown={(event) => startApplicationDrag(event, application)}
                                >
                                    {CONNECTOR_SIDES.map((side) => (
                                        <button
                                            key={side}
                                            type="button"
                                            aria-label={`${application.name} ${side} connector`}
                                            data-application-connector="application-connector"
                                            data-application-id={application.id}
                                            data-connector-side={side}
                                            onPointerDown={(event) => startConnectionDrag(event, application.id, side)}
                                            className={cn(
                                                'application-connector group/connector absolute z-10 flex size-8 items-center justify-center rounded-full opacity-0 transition group-hover/application:opacity-100 md:size-3',
                                                selectedApplicationId === application.id && 'opacity-100',
                                                side === 'top' && 'left-1/2 top-0 -translate-x-1/2 -translate-y-1/2',
                                                side === 'right' && 'right-0 top-1/2 -translate-y-1/2 translate-x-1/2',
                                                side === 'bottom' && 'bottom-0 left-1/2 -translate-x-1/2 translate-y-1/2',
                                                side === 'left' && 'left-0 top-1/2 -translate-x-1/2 -translate-y-1/2',
                                            )}
                                        >
                                            <span className="size-3 rounded-full border-2 border-card bg-warning shadow ring-2 ring-background transition group-hover/connector:scale-125 group-hover/connector:bg-warning/90" />
                                        </button>
                                    ))}

                                    <div className="flex items-start justify-between gap-3">
                                        <div className="min-w-0">
                                            <div className="truncate text-sm font-semibold text-foreground">{application.name}</div>
                                            <div className="mt-1 truncate text-xs text-muted-foreground">{application.image}</div>
                                        </div>
                                        <div className="flex shrink-0 items-center gap-2">
                                            <span
                                                className={cn(
                                                    'rounded-full px-2 py-1 text-[0.625rem] font-semibold uppercase tracking-wide',
                                                    application.status === 'running' && 'bg-emerald-500/15 text-emerald-400',
                                                    application.status === 'creating' && 'bg-warning/15 text-warning',
                                                    application.status === 'failed' && 'bg-destructive/15 text-destructive',
                                                    application.status === 'exited' && 'bg-destructive/15 text-destructive',
                                                )}
                                            >
                                                {application.status}
                                            </span>
                                            <button
                                                type="button"
                                                onPointerDown={(event) => event.stopPropagation()}
                                                onClick={(event) => {
                                                    event.stopPropagation();
                                                    void removeApplication(application);
                                                }}
                                                className="rounded-md border border-destructive/40 px-2 py-1 text-[0.625rem] font-semibold uppercase tracking-wide text-destructive transition hover:bg-destructive/10"
                                            >
                                                Delete
                                            </button>
                                        </div>
                                    </div>

                                    <dl className="mt-4 grid gap-2 text-xs">
                                        <div className="grid grid-cols-[auto_minmax(0,1fr)] gap-3">
                                            <dt className="shrink-0 text-muted-foreground">Server</dt>
                                            <dd className="truncate text-right font-medium text-foreground">
                                                {application.serverName ?? 'Unknown'}
                                            </dd>
                                        </div>
                                        <div className="grid grid-cols-[auto_minmax(0,1fr)] gap-3">
                                            <dt className="shrink-0 text-muted-foreground">Container</dt>
                                            <dd className="truncate text-right font-mono text-[0.6875rem] text-foreground">
                                                {application.containerName}
                                            </dd>
                                        </div>
                                        <div className="grid grid-cols-[auto_minmax(0,1fr)] items-center gap-3">
                                            <dt className="shrink-0 text-muted-foreground">Ingress</dt>
                                            <dd className="flex items-center justify-end gap-2 text-right">
                                                <span className="truncate text-muted-foreground">
                                                    {application.ingressEnabled
                                                        ? `${application.domains.length} domain${application.domains.length === 1 ? '' : 's'} → ${application.internalPort ?? 'no port'}`
                                                        : 'Private'}
                                                </span>
                                                {renderIngressButton(application)}
                                            </dd>
                                        </div>
                                    </dl>
                                </div>
                            ))}
                        </div>
                    </div>
                </main>
            </div>

            {ingressModal && (
                <Dialog
                    open
                    onOpenChange={(open) => {
                        if (!open && !isSavingIngress) {
                            setIngressModal(null);
                        }
                    }}
                >
                    <DialogContent className="max-w-lg" showCloseButton>
                        <DialogHeader>
                            <DialogTitle>Enable app ingress</DialogTitle>
                            <DialogDescription>
                                Route public domains to {ingressModal.application.name} through the server ingress.
                            </DialogDescription>
                        </DialogHeader>

                        <form
                            className="mt-6 flex flex-col gap-4"
                            onSubmit={(event) => {
                                event.preventDefault();
                                void submitApplicationIngress();
                            }}
                        >
                            <Field>
                                <FieldLabel>Domains</FieldLabel>
                                <Input
                                    type="text"
                                    value={ingressModal.domains}
                                    onChange={(event) =>
                                        setIngressModal({ ...ingressModal, domains: event.target.value, error: null })
                                    }
                                    placeholder="example.com, www.example.com"
                                />
                                <span className="text-xs text-muted-foreground">
                                    Use hostnames only, separated by commas. No scheme, path, wildcard, or port.
                                </span>
                            </Field>

                            <Field>
                                <FieldLabel>Internal port</FieldLabel>
                                <Input
                                    type="number"
                                    min="1"
                                    max="65535"
                                    value={ingressModal.internalPort}
                                    onChange={(event) =>
                                        setIngressModal({ ...ingressModal, internalPort: event.target.value, error: null })
                                    }
                                    placeholder="3000"
                                />
                            </Field>

                            {ingressModal.error && (
                                <div className="rounded-lg border border-destructive/30 bg-destructive/10 px-3 py-2 text-sm text-destructive">
                                    <p className="font-medium">Ingress update failed</p>
                                    <p className="mt-1 text-destructive/90">{ingressModal.error}</p>
                                </div>
                            )}

                            <div className="flex justify-end">
                                <Button type="submit" variant="coolify" disabled={isSavingIngress}>
                                    {isSavingIngress ? 'Saving...' : 'Enable ingress'}
                                </Button>
                            </div>
                        </form>
                    </DialogContent>
                </Dialog>
            )}
        </TooltipProvider>
    );
}
