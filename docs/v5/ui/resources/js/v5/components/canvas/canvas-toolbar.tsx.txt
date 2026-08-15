import { memo } from 'react';

import { MAX_CANVAS_ZOOM, MIN_CANVAS_ZOOM } from '@/lib/use-canvas-viewport';
import type { V5NginxServer } from '@/types';

export type CanvasStatusCounts = {
    running: number;
    failed: number;
    unknown: number;
};

type CanvasToolbarProps = {
    nginxServers: V5NginxServer[];
    selectedNginxServerId: string;
    onSelectNginxServer: (serverId: string) => void;
    nginxImage: string;
    onNginxImageChange: (image: string) => void;
    isCreating: boolean;
    onDeploy: () => void;
    onCenter: () => void;
    zoom: number;
    onZoomIn: () => void;
    onZoomOut: () => void;
    isRefreshing: boolean;
    onRefresh: () => void;
    applicationsCount: number;
    statusCounts: CanvasStatusCounts;
};

export const CanvasToolbar = memo(function CanvasToolbar({
    nginxServers,
    selectedNginxServerId,
    onSelectNginxServer,
    nginxImage,
    onNginxImageChange,
    isCreating,
    onDeploy,
    onCenter,
    zoom,
    onZoomIn,
    onZoomOut,
    isRefreshing,
    onRefresh,
    applicationsCount,
    statusCounts,
}: CanvasToolbarProps) {
    return (
        <div className="absolute left-4 top-20 z-30 flex max-w-[calc(100%-2rem)] flex-wrap items-center gap-2 rounded-xl border border-border bg-background/95 p-2 shadow-lg backdrop-blur">
            <select
                aria-label="Select nginx server"
                value={selectedNginxServerId}
                onChange={(event) => onSelectNginxServer(event.target.value)}
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
            <input
                type="text"
                aria-label="Nginx image"
                value={nginxImage}
                onChange={(event) => onNginxImageChange(event.target.value)}
                disabled={isCreating}
                className="w-72 rounded-lg border border-border bg-background px-3 py-2 text-sm font-medium text-foreground transition disabled:cursor-not-allowed disabled:opacity-60"
            />
            <button
                type="button"
                onClick={onDeploy}
                disabled={isCreating || nginxServers.length === 0}
                className="rounded-lg bg-warning px-3 py-2 text-sm font-semibold text-black transition hover:bg-warning/90 disabled:cursor-not-allowed disabled:opacity-60"
            >
                {isCreating ? 'Deploying…' : 'Deploy'}
            </button>
            <button
                type="button"
                onClick={onCenter}
                className="rounded-lg border border-border px-3 py-2 text-sm font-medium text-foreground transition hover:bg-muted"
            >
                Center
            </button>
            <div className="flex items-center overflow-hidden rounded-lg border border-border">
                <button
                    type="button"
                    aria-label="Zoom out"
                    onClick={onZoomOut}
                    className="px-3 py-2 text-sm font-semibold text-foreground transition hover:bg-muted disabled:cursor-not-allowed disabled:opacity-50"
                    disabled={zoom <= MIN_CANVAS_ZOOM}
                >
                    −
                </button>
                <span className="min-w-14 border-x border-border px-2 py-2 text-center text-xs font-medium text-muted-foreground">
                    {Math.round(zoom * 100)}%
                </span>
                <button
                    type="button"
                    aria-label="Zoom in"
                    onClick={onZoomIn}
                    className="px-3 py-2 text-sm font-semibold text-foreground transition hover:bg-muted disabled:cursor-not-allowed disabled:opacity-50"
                    disabled={zoom >= MAX_CANVAS_ZOOM}
                >
                    +
                </button>
            </div>
            <button
                type="button"
                onClick={onRefresh}
                disabled={isRefreshing}
                className="rounded-lg border border-border px-3 py-2 text-sm font-medium text-foreground transition hover:bg-muted disabled:cursor-not-allowed disabled:opacity-60"
            >
                {isRefreshing ? 'Refreshing…' : 'Refresh state'}
            </button>
            <div className="hidden items-center gap-2 px-2 text-xs text-muted-foreground sm:flex">
                <span>{applicationsCount} apps</span>
                <span>•</span>
                <span>{statusCounts.running} running</span>
                {statusCounts.failed > 0 && (
                    <>
                        <span>•</span>
                        <span className="text-destructive">{statusCounts.failed} failed</span>
                    </>
                )}
                {statusCounts.unknown > 0 && (
                    <>
                        <span>•</span>
                        <span>{statusCounts.unknown} unknown</span>
                    </>
                )}
            </div>
        </div>
    );
});
