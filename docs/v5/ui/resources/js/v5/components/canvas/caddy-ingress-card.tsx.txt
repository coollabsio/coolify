import { memo, type PointerEvent } from 'react';

import { statusBadgeClass } from '@/components/canvas/status-badge';
import { cn } from '@/lib/utils';
import type { V5CaddyIngress } from '@/types';

type CaddyIngressCardProps = {
    ingress: V5CaddyIngress;
    onDragStart: (event: PointerEvent<HTMLDivElement>, ingress: V5CaddyIngress) => void;
};

export const CaddyIngressCard = memo(function CaddyIngressCard({ ingress, onDragStart }: CaddyIngressCardProps) {
    return (
        <div
            className="absolute w-80 select-none overflow-hidden rounded-xl border border-warning/40 bg-card p-4 shadow-xl transition-shadow hover:shadow-2xl"
            style={{
                transform: `translate3d(${ingress.canvasX}px, ${ingress.canvasY}px, 0)`,
            }}
            onPointerDown={(event) => onDragStart(event, ingress)}
        >
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <div className="truncate text-sm font-semibold text-foreground">Caddy ingress</div>
                    <div className="mt-1 truncate text-xs text-muted-foreground">{ingress.name}</div>
                </div>
                <span
                    className={cn(
                        'shrink-0 rounded-full px-2 py-1 text-[0.625rem] font-semibold uppercase tracking-wide',
                        statusBadgeClass(ingress.status),
                    )}
                    title={ingress.statusMessage ?? undefined}
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
                    <dd className="truncate text-right font-mono text-[0.6875rem] text-foreground">{ingress.host}</dd>
                </div>
            </dl>
        </div>
    );
});
