import { useMemo } from 'react';

import { shortestConnectionPoints } from '@/lib/canvas-geometry';
import { activeConnectionPorts, type CanvasConnection } from '@/lib/use-canvas-connections';
import { cn } from '@/lib/utils';
import type { V5Application } from '@/types';

type ConnectionPortsEditorProps = {
    connection: CanvasConnection;
    applications: V5Application[];
    portInput: string;
    onPortInputChange: (connectionId: string, value: string) => void;
    onUpdateDirection: (connectionId: string, fromApplicationId: string, toApplicationId: string) => void;
    onAddPort: (connectionId: string) => void;
    onRemovePort: (connectionId: string, port: string) => void;
    onDelete: (connectionId: string) => void;
};

export function ConnectionPortsEditor({
    connection,
    applications,
    portInput,
    onPortInputChange,
    onUpdateDirection,
    onAddPort,
    onRemovePort,
    onDelete,
}: ConnectionPortsEditorProps) {
    const fromApplication = applications.find((candidate) => candidate.id === connection.fromApplicationId);
    const toApplication = applications.find((candidate) => candidate.id === connection.toApplicationId);
    const points = useMemo(
        () => (fromApplication && toApplication ? shortestConnectionPoints(fromApplication, toApplication) : null),
        [fromApplication, toApplication],
    );

    if (!points) {
        return null;
    }

    function applicationDirectionLabel(applicationId: string): string {
        const application = applications.find((candidate) => candidate.id === applicationId);

        if (!application) {
            return 'Unknown app';
        }

        return `${application.name} (${application.id.slice(0, 8)})`;
    }

    const activePorts = activeConnectionPorts(connection);
    const firstApplicationId = connection.applicationIds[0];
    const secondApplicationId = connection.applicationIds[1];
    const isForwardDirection =
        connection.fromApplicationId === firstApplicationId && connection.toApplicationId === secondApplicationId;

    return (
        <div
            className="absolute z-20 flex w-72 -translate-x-1/2 -translate-y-1/2 flex-col gap-3 rounded-md border border-border bg-card p-3 shadow-lg"
            style={{
                left: (points.from.x + points.to.x) / 2,
                top: (points.from.y + points.to.y) / 2,
            }}
            onPointerDown={(event) => event.stopPropagation()}
            onClick={(event) => event.stopPropagation()}
        >
            <div className="space-y-2">
                <div className="text-[0.625rem] font-semibold uppercase tracking-wide text-muted-foreground">Firewall</div>
                <div className="grid gap-1">
                    <button
                        type="button"
                        onClick={() => onUpdateDirection(connection.id, firstApplicationId, secondApplicationId)}
                        className={cn(
                            'rounded-sm border px-2 py-1 text-left text-xs transition',
                            isForwardDirection
                                ? 'border-warning/40 bg-warning/10 text-foreground hover:bg-warning/20'
                                : 'border-border text-muted-foreground hover:bg-muted hover:text-foreground',
                        )}
                    >
                        {applicationDirectionLabel(firstApplicationId)} → {applicationDirectionLabel(secondApplicationId)}
                    </button>
                    <button
                        type="button"
                        onClick={() => onUpdateDirection(connection.id, secondApplicationId, firstApplicationId)}
                        className={cn(
                            'rounded-sm border px-2 py-1 text-left text-xs transition',
                            !isForwardDirection
                                ? 'border-warning/40 bg-warning/10 text-foreground hover:bg-warning/20'
                                : 'border-border text-muted-foreground hover:bg-muted hover:text-foreground',
                        )}
                    >
                        {applicationDirectionLabel(secondApplicationId)} → {applicationDirectionLabel(firstApplicationId)}
                    </button>
                </div>
            </div>

            <div className="space-y-2">
                <div className="text-[0.625rem] font-semibold uppercase tracking-wide text-muted-foreground">Allowed ports</div>
                <div className="flex flex-wrap gap-1">
                    {activePorts.length === 0 && <span className="text-xs text-muted-foreground">No ports yet.</span>}
                    {activePorts.map((port) => (
                        <button
                            key={port}
                            type="button"
                            onClick={() => onRemovePort(connection.id, port)}
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
                        value={portInput}
                        onChange={(event) => onPortInputChange(connection.id, event.target.value)}
                        onKeyDown={(event) => {
                            if (event.key === 'Enter') {
                                onAddPort(connection.id);
                            }
                        }}
                        className="min-w-0 flex-1 rounded-sm border border-border bg-background px-2 py-1 text-xs text-foreground outline-none transition focus:border-warning"
                    />
                    <button
                        type="button"
                        onClick={() => onAddPort(connection.id)}
                        className="rounded-sm border border-border px-2 py-1 text-xs font-medium text-foreground transition hover:bg-muted"
                    >
                        Add
                    </button>
                </div>
            </div>

            <button
                type="button"
                aria-label="Delete connection"
                onClick={() => onDelete(connection.id)}
                className="rounded-sm border border-destructive/40 px-2 py-1 text-[0.625rem] font-semibold uppercase tracking-wide text-destructive transition hover:bg-destructive/10"
            >
                Delete
            </button>
        </div>
    );
}
