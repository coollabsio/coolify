import { Head } from '@inertiajs/react';
import { useCallback, useEffect, useMemo, useRef, useState, type MouseEvent, type PointerEvent } from 'react';

import { AppNavbar } from '@/components/app-navbar';
import { ApplicationCard } from '@/components/canvas/application-card';
import { ApplicationInspectorSheet } from '@/components/canvas/application-inspector-sheet';
import { CaddyIngressCard } from '@/components/canvas/caddy-ingress-card';
import { CanvasNotice } from '@/components/canvas/canvas-notice';
import { CanvasToolbar } from '@/components/canvas/canvas-toolbar';
import { ConnectionLines } from '@/components/canvas/connection-lines';
import { ConnectionPortsEditor } from '@/components/canvas/connection-ports-editor';
import { IngressDialog } from '@/components/canvas/ingress-dialog';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { TooltipProvider } from '@/components/ui/tooltip';
import { canvasRequest } from '@/lib/canvas-api';
import {
    connectorPoint,
    resolveApplicationPosition,
    resolveIngressPosition,
    settleCanvasResources,
    type ConnectionEndpoint,
    type ConnectorSide,
    type DraftConnection,
} from '@/lib/canvas-geometry';
import { runOptimisticUpdate } from '@/lib/optimistic';
import { useApplicationIngress } from '@/lib/use-application-ingress';
import { useCanvasConnections } from '@/lib/use-canvas-connections';
import { useCanvasResourceMerge } from '@/lib/use-canvas-resource-merge';
import { useCanvasViewport, type Viewport } from '@/lib/use-canvas-viewport';
import { usePendingIds } from '@/lib/use-pending-ids';
import type { V5Application, V5CaddyIngress, V5DashboardProps } from '@/types';

const DEFAULT_NGINX_IMAGE = 'docker.io/library/nginx:alpine';

type CanvasPosition = {
    canvasX: number;
    canvasY: number;
};

type DeleteApplicationResponse = {
    message?: string;
    can_delete_locally?: boolean;
};

type PendingLocalDelete = {
    application: V5Application;
    message: string;
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
    selectedApplicationUuid = null,
}: V5DashboardProps) {
    const [applications, setApplications] = useState<V5Application[]>(initialApplications);
    const [ingresses, setIngresses] = useState<V5CaddyIngress[]>(caddyIngresses);
    const [selectedApplicationId, setSelectedApplicationId] = useState<string | null>(null);
    const [selectedInspectorApplicationId, setSelectedInspectorApplicationId] = useState<string | null>(null);
    const [draftConnection, setDraftConnection] = useState<DraftConnection | null>(null);
    const [pointerState, setPointerState] = useState<PointerState | null>(null);
    const [isCreating, setIsCreating] = useState(false);
    const [selectedNginxServerId, setSelectedNginxServerId] = useState<string>(nginxServers[0]?.id ?? '');
    const [nginxImage, setNginxImage] = useState<string>(DEFAULT_NGINX_IMAGE);
    const [isRefreshing, setIsRefreshing] = useState(false);
    const [notice, setNotice] = useState<string | null>(null);
    const [pendingLocalDelete, setPendingLocalDelete] = useState<PendingLocalDelete | null>(null);
    const deletingApplications = usePendingIds<string>();

    const applicationsRef = useRef(applications);

    applicationsRef.current = applications;

    /**
     * Cards that are mid-drag or whose position PATCH has not landed yet keep
     * their local canvasX/canvasY when a websocket merge arrives.
     */
    const locallyPositionedApplicationIdsRef = useRef<Set<string>>(new Set());
    const locallyPositionedIngressIdsRef = useRef<Set<string>>(new Set());

    const { canvasRef, viewport, setViewport, zoomCanvas, handleCanvasWheel, centerOnCanvasNodes, canvasPointFromPointer } =
        useCanvasViewport();
    const {
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
    } = useCanvasConnections(initialResourceConnections, setNotice);

    const applyApplicationUpdate = useCallback((application: V5Application): void => {
        setApplications((currentApplications) =>
            currentApplications.map((candidate) => (candidate.id === application.id ? application : candidate)),
        );
    }, []);

    const {
        ingressModal,
        closeIngressModal,
        setIngressModalDomains,
        setIngressModalInternalPort,
        submitApplicationIngress,
        toggleApplicationIngress,
        savingIngressApplications,
    } = useApplicationIngress({ notify: setNotice, onApplicationUpdated: applyApplicationUpdate });

    const hasCanvasNodes = applications.length > 0 || ingresses.length > 0;
    const statusCounts = useMemo(
        () => ({
            running: applications.filter((application) => application.effectiveStatus === 'running').length,
            failed: applications.filter((application) => application.effectiveStatus === 'failed').length,
            unknown: applications.filter((application) => application.effectiveStatus === 'unknown').length,
        }),
        [applications],
    );
    const selectedInspectorApplication = useMemo(
        () => applications.find((application) => application.id === selectedInspectorApplicationId) ?? null,
        [applications, selectedInspectorApplicationId],
    );
    const selectedConnection = useMemo(
        () => connections.find((connection) => connection.id === selectedConnectionId) ?? null,
        [connections, selectedConnectionId],
    );

    useEffect(() => {
        const settledResources = settleCanvasResources(initialApplications, caddyIngresses);

        setApplications(settledResources.applications);
        setIngresses(settledResources.ingresses);
        resetConnections(initialResourceConnections);
        setSelectedNginxServerId((currentServerId) => currentServerId || nginxServers[0]?.id || '');
        const linkedApplicationExists = settledResources.applications.some((application) => application.id === selectedApplicationUuid);
        setSelectedApplicationId(linkedApplicationExists ? selectedApplicationUuid : null);
        setSelectedInspectorApplicationId(linkedApplicationExists ? selectedApplicationUuid : null);
        centerOnCanvasNodes(settledResources.applications, settledResources.ingresses);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [
        initialApplications,
        caddyIngresses,
        initialResourceConnections,
        nginxServers[0]?.id,
        selectedProjectUuid,
        selectedEnvironmentUuid,
        selectedApplicationUuid,
    ]);

    useCanvasResourceMerge({
        teamId: currentTeam?.id ?? null,
        selectedProjectUuid,
        selectedEnvironmentUuid,
        setApplications,
        setIngresses,
        locallyPositionedApplicationIds: locallyPositionedApplicationIdsRef,
        locallyPositionedIngressIds: locallyPositionedIngressIdsRef,
    });

    const persistApplicationPosition = useCallback(async (application: V5Application, previousPosition: CanvasPosition): Promise<void> => {
        await runOptimisticUpdate({
            rollback: () =>
                setApplications((currentApplications) =>
                    currentApplications.map((candidate) => (candidate.id === application.id ? { ...candidate, ...previousPosition } : candidate)),
                ),
            request: async () => {
                const response = await canvasRequest(`/v5/applications/${application.id}/position`, {
                    method: 'PATCH',
                    body: {
                        canvas_x: application.canvasX,
                        canvas_y: application.canvasY,
                    },
                });

                return response.ok ? { ok: true, payload: undefined } : { ok: false };
            },
            fallbackErrorMessage: 'Could not save the card position.',
            notify: setNotice,
            onSettled: () => locallyPositionedApplicationIdsRef.current.delete(application.id),
        });
    }, []);

    const persistCaddyIngressPosition = useCallback(async (ingress: V5CaddyIngress, previousPosition: CanvasPosition): Promise<void> => {
        await runOptimisticUpdate({
            rollback: () =>
                setIngresses((currentIngresses) =>
                    currentIngresses.map((candidate) => (candidate.id === ingress.id ? { ...candidate, ...previousPosition } : candidate)),
                ),
            request: async () => {
                const response = await canvasRequest(`/v5/caddy-ingresses/${ingress.id}/position`, {
                    method: 'PATCH',
                    body: {
                        canvas_x: ingress.canvasX,
                        canvas_y: ingress.canvasY,
                    },
                });

                return response.ok ? { ok: true, payload: undefined } : { ok: false };
            },
            fallbackErrorMessage: 'Could not save the card position.',
            notify: setNotice,
            onSettled: () => locallyPositionedIngressIdsRef.current.delete(ingress.id),
        });
    }, []);

    const removeApplication = useCallback(
        async (application: V5Application, deleteLocally = false): Promise<void> => {
            setNotice(null);
            deletingApplications.start(application.id);

            try {
                const response = await canvasRequest(`/v5/applications/${application.id}`, {
                    method: 'DELETE',
                    body: deleteLocally ? { delete_locally: true } : undefined,
                });

                if (!response.ok) {
                    const payload = (await response.json().catch(() => ({}))) as DeleteApplicationResponse;
                    const message = payload.message ?? 'Could not delete application.';

                    setNotice(message);

                    if (payload.can_delete_locally && !deleteLocally) {
                        setPendingLocalDelete({ application, message });
                    }

                    return;
                }

                setApplications((currentApplications) => currentApplications.filter((candidate) => candidate.id !== application.id));
                removeConnectionsForApplication(application.id);
            } catch (error) {
                setNotice(error instanceof Error ? error.message : 'Could not delete application.');
            } finally {
                deletingApplications.finish(application.id);
            }
        },
        [deletingApplications.start, deletingApplications.finish, removeConnectionsForApplication],
    );

    const deleteApplication = useCallback(
        (application: V5Application): void => {
            void removeApplication(application);
        },
        [removeApplication],
    );

    const addNginx = useCallback(async (): Promise<void> => {
        setIsCreating(true);
        setNotice(null);

        try {
            const response = await canvasRequest('/v5/applications/nginx', {
                method: 'POST',
                body: {
                    server_uuid: selectedNginxServerId || null,
                    image: nginxImage.trim() || DEFAULT_NGINX_IMAGE,
                },
            });
            const payload = (await response.json()) as { application?: V5Application; message?: string };

            if (!response.ok || !payload.application) {
                setNotice(payload.message ?? 'Could not deploy nginx.');

                return;
            }

            const settledResources = settleCanvasResources([...applicationsRef.current, payload.application], ingresses);
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
                locallyPositionedApplicationIdsRef.current.add(settledApplication.id);
                void persistApplicationPosition(settledApplication, {
                    canvasX: payload.application.canvasX,
                    canvasY: payload.application.canvasY,
                });
            }
        } catch (error) {
            setNotice(error instanceof Error ? error.message : 'Could not deploy nginx.');
        } finally {
            setIsCreating(false);
        }
    }, [selectedNginxServerId, nginxImage, ingresses, centerOnCanvasNodes, persistApplicationPosition]);

    const refreshApplications = useCallback(async (): Promise<void> => {
        setIsRefreshing(true);
        setNotice(null);

        try {
            const response = await canvasRequest('/v5/applications/refresh', { method: 'POST' });
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
    }, [ingresses]);

    const startApplicationDrag = useCallback(
        (event: PointerEvent<HTMLDivElement>, application: V5Application): void => {
            event.stopPropagation();
            event.currentTarget.setPointerCapture(event.pointerId);
            setSelectedConnectionId(null);
            setSelectedApplicationId(application.id);
            locallyPositionedApplicationIdsRef.current.add(application.id);
            setPointerState({
                type: 'app',
                pointerId: event.pointerId,
                applicationId: application.id,
                startClientX: event.clientX,
                startClientY: event.clientY,
                startX: application.canvasX,
                startY: application.canvasY,
            });
        },
        [setSelectedConnectionId],
    );

    const startIngressDrag = useCallback((event: PointerEvent<HTMLDivElement>, ingress: V5CaddyIngress): void => {
        event.stopPropagation();
        event.currentTarget.setPointerCapture(event.pointerId);
        locallyPositionedIngressIdsRef.current.add(ingress.id);
        setPointerState({
            type: 'ingress',
            pointerId: event.pointerId,
            ingressId: ingress.id,
            startClientX: event.clientX,
            startClientY: event.clientY,
            startX: ingress.canvasX,
            startY: ingress.canvasY,
        });
    }, []);

    const startConnectionDrag = useCallback(
        (event: PointerEvent<HTMLButtonElement>, applicationId: string, side: ConnectorSide): void => {
            event.stopPropagation();

            const from = { applicationId, side };
            const fromApplication = applicationsRef.current.find((candidate) => candidate.id === applicationId);
            const startPoint = fromApplication ? connectorPoint(fromApplication, side) : canvasPointFromPointer(event);

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
        },
        [canvasPointFromPointer],
    );

    const selectConnection = useCallback(
        (event: MouseEvent<SVGLineElement>, connectionId: string): void => {
            event.stopPropagation();
            setSelectedConnectionId(connectionId);
            setSelectedApplicationId(null);
        },
        [setSelectedConnectionId],
    );

    const openApplicationInspector = useCallback((event: MouseEvent<HTMLElement>, application: V5Application): void => {
        event.stopPropagation();
        setSelectedApplicationId(application.id);
        setSelectedInspectorApplicationId(application.id);
    }, []);

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
                const updatedApplication = resolveApplicationPosition(
                    {
                        ...application,
                        canvasX: Math.round(pointerState.startX + deltaX / viewport.zoom),
                        canvasY: Math.round(pointerState.startY + deltaY / viewport.zoom),
                    },
                    applications,
                    ingresses,
                );

                setApplications((currentApplications) =>
                    currentApplications.map((candidate) => (candidate.id === updatedApplication.id ? updatedApplication : candidate)),
                );
                void persistApplicationPosition(updatedApplication, {
                    canvasX: pointerState.startX,
                    canvasY: pointerState.startY,
                });
            }
        }

        if (pointerState.type === 'ingress') {
            const ingress = ingresses.find((candidate) => candidate.id === pointerState.ingressId);

            if (ingress) {
                const updatedIngress = resolveIngressPosition(
                    {
                        ...ingress,
                        canvasX: Math.round(pointerState.startX + deltaX / viewport.zoom),
                        canvasY: Math.round(pointerState.startY + deltaY / viewport.zoom),
                    },
                    applications,
                    ingresses,
                );

                setIngresses((currentIngresses) =>
                    currentIngresses.map((candidate) => (candidate.id === updatedIngress.id ? updatedIngress : candidate)),
                );
                void persistCaddyIngressPosition(updatedIngress, {
                    canvasX: pointerState.startX,
                    canvasY: pointerState.startY,
                });
            }
        }

        setPointerState(null);
    }

    const deployNginx = useCallback((): void => {
        void addNginx();
    }, [addNginx]);
    const refreshCanvas = useCallback((): void => {
        void refreshApplications();
    }, [refreshApplications]);
    const centerCanvas = useCallback((): void => {
        centerOnCanvasNodes(applications, ingresses);
    }, [applications, ingresses, centerOnCanvasNodes]);
    const zoomIn = useCallback((): void => zoomCanvas(1), [zoomCanvas]);
    const zoomOut = useCallback((): void => zoomCanvas(-1), [zoomCanvas]);
    const dismissNotice = useCallback((): void => setNotice(null), []);
    const closeInspector = useCallback((): void => setSelectedInspectorApplicationId(null), []);
    const submitIngress = useCallback((): void => {
        void submitApplicationIngress();
    }, [submitApplicationIngress]);

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
                    <CanvasToolbar
                        nginxServers={nginxServers}
                        selectedNginxServerId={selectedNginxServerId}
                        onSelectNginxServer={setSelectedNginxServerId}
                        nginxImage={nginxImage}
                        onNginxImageChange={setNginxImage}
                        isCreating={isCreating}
                        onDeploy={deployNginx}
                        onCenter={centerCanvas}
                        zoom={viewport.zoom}
                        onZoomIn={zoomIn}
                        onZoomOut={zoomOut}
                        isRefreshing={isRefreshing}
                        onRefresh={refreshCanvas}
                        applicationsCount={applications.length}
                        statusCounts={statusCounts}
                    />

                    {notice && <CanvasNotice message={notice} onDismiss={dismissNotice} />}

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
                                    Click Deploy to deploy a test container on one of your v5 servers.
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
                                <CaddyIngressCard key={`caddy-ingress-${ingress.id}`} ingress={ingress} onDragStart={startIngressDrag} />
                            ))}

                            <ConnectionLines
                                connections={connections}
                                applications={applications}
                                selectedConnectionId={selectedConnectionId}
                                draftConnection={draftConnection}
                                onSelectConnection={selectConnection}
                            />

                            {selectedConnection && (
                                <ConnectionPortsEditor
                                    connection={selectedConnection}
                                    applications={applications}
                                    portInput={connectionPortInput[selectedConnection.id] ?? ''}
                                    onPortInputChange={setConnectionPortDraft}
                                    onUpdateDirection={updateConnectionDirection}
                                    onAddPort={addConnectionPort}
                                    onRemovePort={removeConnectionPort}
                                    onDelete={deleteConnection}
                                />
                            )}

                            {applications.map((application) => (
                                <ApplicationCard
                                    key={application.id}
                                    application={application}
                                    isSelected={selectedApplicationId === application.id}
                                    isDeleting={deletingApplications.pendingIds.has(application.id)}
                                    isIngressSaving={savingIngressApplications.pendingIds.has(application.id)}
                                    onDragStart={startApplicationDrag}
                                    onOpenInspector={openApplicationInspector}
                                    onDelete={deleteApplication}
                                    onToggleIngress={toggleApplicationIngress}
                                    onConnectorPointerDown={startConnectionDrag}
                                />
                            ))}
                        </div>
                    </div>
                </main>
            </div>

            <ApplicationInspectorSheet
                application={selectedInspectorApplication}
                isIngressSaving={
                    selectedInspectorApplication !== null && savingIngressApplications.pendingIds.has(selectedInspectorApplication.id)
                }
                onToggleIngress={toggleApplicationIngress}
                onClose={closeInspector}
            />

            <Dialog open={pendingLocalDelete !== null} onOpenChange={(open) => !open && setPendingLocalDelete(null)}>
                <DialogContent className="max-w-md" showCloseButton={false}>
                    <DialogHeader>
                        <DialogTitle>Delete from Coolify only?</DialogTitle>
                        <DialogDescription>
                            Coolify could not reach the server to clean up containers, volumes, networks, or ingress config.
                        </DialogDescription>
                    </DialogHeader>

                    {pendingLocalDelete && (
                        <div className="flex flex-col gap-3 text-sm text-muted-foreground">
                            <p className="break-words rounded-md border border-border bg-muted/40 p-3 font-mono text-xs">
                                {pendingLocalDelete.message}
                            </p>
                            <p>
                                Deleting locally removes {pendingLocalDelete.application.name} from Coolify, but orphaned resources may remain on the unreachable server.
                            </p>
                        </div>
                    )}

                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => setPendingLocalDelete(null)}>
                            Cancel
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => {
                                const application = pendingLocalDelete?.application;
                                setPendingLocalDelete(null);

                                if (application) {
                                    void removeApplication(application);
                                }
                            }}
                        >
                            Retry
                        </Button>
                        <Button
                            type="button"
                            variant="destructive"
                            onClick={() => {
                                const application = pendingLocalDelete?.application;
                                setPendingLocalDelete(null);

                                if (application) {
                                    void removeApplication(application, true);
                                }
                            }}
                        >
                            Delete from Coolify only
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {ingressModal && (
                <IngressDialog
                    modal={ingressModal}
                    isSaving={savingIngressApplications.pendingIds.has(ingressModal.application.id)}
                    onDomainsChange={setIngressModalDomains}
                    onInternalPortChange={setIngressModalInternalPort}
                    onSubmit={submitIngress}
                    onClose={closeIngressModal}
                />
            )}
        </TooltipProvider>
    );
}
