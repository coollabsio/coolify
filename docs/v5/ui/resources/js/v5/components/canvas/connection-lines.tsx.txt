import { memo, useMemo, type MouseEvent } from 'react';

import { connectorPoint, shortestConnectionPoints, type DraftConnection } from '@/lib/canvas-geometry';
import { cn } from '@/lib/utils';
import type { CanvasConnection } from '@/lib/use-canvas-connections';
import type { V5Application } from '@/types';

type ConnectionLineProps = {
    connection: CanvasConnection;
    fromApplication: V5Application;
    toApplication: V5Application;
    isSelected: boolean;
    onSelect: (event: MouseEvent<SVGLineElement>, connectionId: string) => void;
};

const ConnectionLine = memo(function ConnectionLine({ connection, fromApplication, toApplication, isSelected, onSelect }: ConnectionLineProps) {
    const points = useMemo(() => shortestConnectionPoints(fromApplication, toApplication), [fromApplication, toApplication]);

    return (
        <g>
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
                onClick={(event) => onSelect(event, connection.id)}
            />
            <line
                x1={points.from.x}
                y1={points.from.y}
                x2={points.to.x}
                y2={points.to.y}
                className={cn('pointer-events-none', isSelected ? 'stroke-destructive' : 'stroke-warning')}
                strokeWidth={isSelected ? 4 : 2}
                strokeDasharray="6 6"
                strokeLinecap="round"
                markerEnd={isSelected ? 'url(#dashboard-connection-arrow)' : undefined}
            />
        </g>
    );
});

type ConnectionLinesProps = {
    connections: CanvasConnection[];
    applications: V5Application[];
    selectedConnectionId: string | null;
    draftConnection: DraftConnection | null;
    onSelectConnection: (event: MouseEvent<SVGLineElement>, connectionId: string) => void;
};

export function ConnectionLines({ connections, applications, selectedConnectionId, draftConnection, onSelectConnection }: ConnectionLinesProps) {
    const applicationsById = useMemo(() => new Map(applications.map((application) => [application.id, application])), [applications]);
    const draftFromApplication = draftConnection ? applicationsById.get(draftConnection.from.applicationId) : undefined;
    const draftFrom = draftConnection && draftFromApplication ? connectorPoint(draftFromApplication, draftConnection.from.side) : null;

    return (
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
                const fromApplication = applicationsById.get(connection.fromApplicationId);
                const toApplication = applicationsById.get(connection.toApplicationId);

                if (!fromApplication || !toApplication) {
                    return null;
                }

                return (
                    <ConnectionLine
                        key={connection.id}
                        connection={connection}
                        fromApplication={fromApplication}
                        toApplication={toApplication}
                        isSelected={selectedConnectionId === connection.id}
                        onSelect={onSelectConnection}
                    />
                );
            })}
            {draftConnection && draftFrom && (
                <line
                    x1={draftFrom.x}
                    y1={draftFrom.y}
                    x2={draftConnection.toX}
                    y2={draftConnection.toY}
                    className="stroke-warning/70"
                    strokeWidth={2}
                    strokeDasharray="6 6"
                    strokeLinecap="round"
                />
            )}
        </svg>
    );
}
