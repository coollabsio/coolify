import { describe, expect, it } from 'vitest';
import {
    APPLICATION_CARD_HEIGHT,
    APPLICATION_CARD_WIDTH,
    CANVAS_CARD_GAP,
    connectorPoint,
    resolveApplicationPosition,
    settleCanvasResources,
    shortestConnectionPoints,
} from '@/lib/canvas-geometry';
import type { V5Application, V5CaddyIngress } from '@/types';

function application(id: string, canvasX: number, canvasY: number): V5Application {
    return { id, canvasX, canvasY } as V5Application;
}

function ingress(id: string, canvasX: number, canvasY: number): V5CaddyIngress {
    return { id, canvasX, canvasY } as V5CaddyIngress;
}

describe('connectorPoint', () => {
    const node = { canvasX: 100, canvasY: 200 };

    it('returns the midpoint of each card edge', () => {
        expect(connectorPoint(node, 'top')).toEqual({ x: 100 + APPLICATION_CARD_WIDTH / 2, y: 200 });
        expect(connectorPoint(node, 'right')).toEqual({ x: 100 + APPLICATION_CARD_WIDTH, y: 200 + APPLICATION_CARD_HEIGHT / 2 });
        expect(connectorPoint(node, 'bottom')).toEqual({ x: 100 + APPLICATION_CARD_WIDTH / 2, y: 200 + APPLICATION_CARD_HEIGHT });
        expect(connectorPoint(node, 'left')).toEqual({ x: 100, y: 200 + APPLICATION_CARD_HEIGHT / 2 });
    });
});

describe('shortestConnectionPoints', () => {
    it('connects facing edges of horizontally separated cards', () => {
        const left = { canvasX: 0, canvasY: 0 };
        const right = { canvasX: 1000, canvasY: 0 };

        const { from, to } = shortestConnectionPoints(left, right);

        expect(from).toEqual(connectorPoint(left, 'right'));
        expect(to).toEqual(connectorPoint(right, 'left'));
    });

    it('connects facing edges of vertically separated cards', () => {
        const top = { canvasX: 0, canvasY: 0 };
        const bottom = { canvasX: 0, canvasY: 1000 };

        const { from, to } = shortestConnectionPoints(top, bottom);

        expect(from).toEqual(connectorPoint(top, 'bottom'));
        expect(to).toEqual(connectorPoint(bottom, 'top'));
    });
});

describe('settleCanvasResources', () => {
    it('keeps non-overlapping resources in place', () => {
        const apps = [application('app-1', 0, 0), application('app-2', 1000, 0)];

        const settled = settleCanvasResources(apps, []);

        expect(settled.applications[0]).toMatchObject({ canvasX: 0, canvasY: 0 });
        expect(settled.applications[1]).toMatchObject({ canvasX: 1000, canvasY: 0 });
    });

    it('separates overlapping applications and ingresses', () => {
        const settled = settleCanvasResources([application('app-1', 0, 0)], [ingress('ingress-1', 0, 0)]);

        const app = settled.applications[0];
        const caddy = settled.ingresses[0];
        const horizontalGap = Math.abs(app.canvasX - caddy.canvasX);
        const verticalGap = Math.abs(app.canvasY - caddy.canvasY);

        expect(
            horizontalGap >= APPLICATION_CARD_WIDTH + CANVAS_CARD_GAP || verticalGap >= APPLICATION_CARD_HEIGHT + CANVAS_CARD_GAP,
        ).toBe(true);
    });
});

describe('resolveApplicationPosition', () => {
    it('moves a dragged application off an occupied slot', () => {
        const occupied = application('app-1', 0, 0);
        const dragged = application('app-2', 8, 8);

        const resolved = resolveApplicationPosition(dragged, [occupied, dragged], []);

        expect(resolved.canvasX !== 8 || resolved.canvasY !== 8).toBe(true);
    });

    it('keeps a dragged application on a free slot', () => {
        const occupied = application('app-1', 0, 0);
        const dragged = application('app-2', 1000, 1000);

        const resolved = resolveApplicationPosition(dragged, [occupied, dragged], []);

        expect(resolved).toMatchObject({ canvasX: 1000, canvasY: 1000 });
    });
});
