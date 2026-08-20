import { memo, type MouseEvent, type PointerEvent } from 'react';

import { ApplicationIngressButton } from '@/components/canvas/application-ingress-button';
import { statusBadgeClass } from '@/components/canvas/status-badge';
import { CONNECTOR_SIDES, type ConnectorSide } from '@/lib/canvas-geometry';
import { cn } from '@/lib/utils';
import type { V5Application } from '@/types';

type ApplicationCardProps = {
    application: V5Application;
    isSelected: boolean;
    isDeleting: boolean;
    isIngressSaving: boolean;
    onDragStart: (event: PointerEvent<HTMLDivElement>, application: V5Application) => void;
    onOpenInspector: (event: MouseEvent<HTMLElement>, application: V5Application) => void;
    onDelete: (application: V5Application) => void;
    onToggleIngress: (application: V5Application) => void;
    onConnectorPointerDown: (event: PointerEvent<HTMLButtonElement>, applicationId: string, side: ConnectorSide) => void;
};

export const ApplicationCard = memo(function ApplicationCard({
    application,
    isSelected,
    isDeleting,
    isIngressSaving,
    onDragStart,
    onOpenInspector,
    onDelete,
    onToggleIngress,
    onConnectorPointerDown,
}: ApplicationCardProps) {
    return (
        <div
            data-application-card="application-card"
            data-application-id={application.id}
            className="group/application absolute h-40 w-80 select-none overflow-visible rounded-xl border border-border bg-card p-4 shadow-xl transition-shadow hover:shadow-2xl"
            style={{
                transform: `translate3d(${application.canvasX}px, ${application.canvasY}px, 0)`,
            }}
            onPointerDown={(event) => onDragStart(event, application)}
            onDoubleClick={(event) => onOpenInspector(event, application)}
        >
            {CONNECTOR_SIDES.map((side) => (
                <button
                    key={side}
                    type="button"
                    aria-label={`${application.name} ${side} connector`}
                    data-application-connector="application-connector"
                    data-application-id={application.id}
                    data-connector-side={side}
                    onPointerDown={(event) => onConnectorPointerDown(event, application.id, side)}
                    className={cn(
                        'application-connector group/connector absolute z-10 flex size-8 items-center justify-center rounded-full opacity-0 transition group-hover/application:opacity-100 md:size-3',
                        isSelected && 'opacity-100',
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
                            statusBadgeClass(application.effectiveStatus),
                        )}
                        title={application.effectiveStatusMessage ?? undefined}
                    >
                        {application.effectiveStatus}
                    </span>
                    <button
                        type="button"
                        onPointerDown={(event) => event.stopPropagation()}
                        onClick={(event) => onOpenInspector(event, application)}
                        className="rounded-md border border-border px-2 py-1 text-[0.625rem] font-semibold uppercase tracking-wide text-foreground transition hover:bg-muted"
                    >
                        Configure
                    </button>
                    <button
                        type="button"
                        onPointerDown={(event) => event.stopPropagation()}
                        onClick={(event) => {
                            event.stopPropagation();
                            onDelete(application);
                        }}
                        disabled={isDeleting}
                        className="rounded-md border border-destructive/40 px-2 py-1 text-[0.625rem] font-semibold uppercase tracking-wide text-destructive transition hover:bg-destructive/10 disabled:cursor-not-allowed disabled:opacity-60"
                    >
                        {isDeleting ? 'Deleting…' : 'Delete'}
                    </button>
                </div>
            </div>

            <dl className="mt-4 grid gap-2 text-xs">
                <div className="grid grid-cols-[auto_minmax(0,1fr)] gap-3">
                    <dt className="shrink-0 text-muted-foreground">Server</dt>
                    <dd className="truncate text-right font-medium text-foreground">
                        {application.serverName ?? 'Unknown'}
                        {!application.isServerReachable && <span className="ml-2 text-destructive">(unreachable)</span>}
                    </dd>
                </div>
                <div className="grid grid-cols-[auto_minmax(0,1fr)] gap-3">
                    <dt className="shrink-0 text-muted-foreground">Container</dt>
                    <dd className="truncate text-right font-mono text-[0.6875rem] text-foreground">{application.containerName}</dd>
                </div>
                <div className="grid grid-cols-[auto_minmax(0,1fr)] items-center gap-3">
                    <dt className="shrink-0 text-muted-foreground">Ingress</dt>
                    <dd className="flex items-center justify-end gap-2 text-right">
                        <span className="truncate text-muted-foreground">
                            {application.ingressEnabled
                                ? `${application.domains.length} domain${application.domains.length === 1 ? '' : 's'} → ${application.internalPort ?? 'no port'}`
                                : 'Private'}
                        </span>
                        <ApplicationIngressButton application={application} isSaving={isIngressSaving} onToggle={onToggleIngress} />
                    </dd>
                </div>
            </dl>
        </div>
    );
});
