import { resolveCanvasNodeLayout, resolveCanvasNodePosition, type CanvasNodeBounds } from '@/lib/canvas-collision';
import type { V5Application, V5CaddyIngress } from '@/types';

export const APPLICATION_CARD_WIDTH = 320;
export const APPLICATION_CARD_HEIGHT = 160;
export const CANVAS_CARD_GAP = 16;

export type ConnectorSide = 'top' | 'right' | 'bottom' | 'left';

export const CONNECTOR_SIDES: ConnectorSide[] = ['top', 'right', 'bottom', 'left'];

export type CanvasPoint = {
    x: number;
    y: number;
};

export type CanvasCardNode = {
    canvasX: number;
    canvasY: number;
};

export type ConnectionEndpoint = {
    applicationId: string;
    side: ConnectorSide;
};

export type DraftConnection = {
    from: ConnectionEndpoint;
    toX: number;
    toY: number;
};

export function connectorPoint(node: CanvasCardNode, side: ConnectorSide): CanvasPoint {
    switch (side) {
        case 'top':
            return { x: node.canvasX + APPLICATION_CARD_WIDTH / 2, y: node.canvasY };
        case 'right':
            return { x: node.canvasX + APPLICATION_CARD_WIDTH, y: node.canvasY + APPLICATION_CARD_HEIGHT / 2 };
        case 'bottom':
            return { x: node.canvasX + APPLICATION_CARD_WIDTH / 2, y: node.canvasY + APPLICATION_CARD_HEIGHT };
        case 'left':
            return { x: node.canvasX, y: node.canvasY + APPLICATION_CARD_HEIGHT / 2 };
    }
}

export function shortestConnectionPoints(fromNode: CanvasCardNode, toNode: CanvasCardNode): { from: CanvasPoint; to: CanvasPoint } {
    let shortest = {
        from: connectorPoint(fromNode, 'top'),
        to: connectorPoint(toNode, 'top'),
        distance: Number.POSITIVE_INFINITY,
    };

    for (const fromSide of CONNECTOR_SIDES) {
        const from = connectorPoint(fromNode, fromSide);

        for (const toSide of CONNECTOR_SIDES) {
            const to = connectorPoint(toNode, toSide);
            const distance = Math.hypot(from.x - to.x, from.y - to.y);

            if (distance < shortest.distance) {
                shortest = { from, to, distance };
            }
        }
    }

    return { from: shortest.from, to: shortest.to };
}

function applicationBounds(application: V5Application): CanvasNodeBounds {
    return {
        id: `application-${application.id}`,
        x: application.canvasX,
        y: application.canvasY,
        width: APPLICATION_CARD_WIDTH,
        height: APPLICATION_CARD_HEIGHT,
    };
}

function ingressBounds(ingress: V5CaddyIngress): CanvasNodeBounds {
    return {
        id: `ingress-${ingress.id}`,
        x: ingress.canvasX,
        y: ingress.canvasY,
        width: APPLICATION_CARD_WIDTH,
        height: APPLICATION_CARD_HEIGHT,
    };
}

export function canvasCollisionNodes(applications: V5Application[], ingresses: V5CaddyIngress[]): CanvasNodeBounds[] {
    return [...applications.map(applicationBounds), ...ingresses.map(ingressBounds)];
}

export function settleCanvasResources(
    nextApplications: V5Application[],
    nextIngresses: V5CaddyIngress[],
): { applications: V5Application[]; ingresses: V5CaddyIngress[] } {
    const settledNodes = resolveCanvasNodeLayout(canvasCollisionNodes(nextApplications, nextIngresses), CANVAS_CARD_GAP);
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

export function resolveApplicationPosition(
    application: V5Application,
    applications: V5Application[],
    ingresses: V5CaddyIngress[],
): V5Application {
    const position = resolveCanvasNodePosition(applicationBounds(application), canvasCollisionNodes(applications, ingresses), CANVAS_CARD_GAP);

    return { ...application, canvasX: position.x, canvasY: position.y };
}

export function resolveIngressPosition(
    ingress: V5CaddyIngress,
    applications: V5Application[],
    ingresses: V5CaddyIngress[],
): V5CaddyIngress {
    const position = resolveCanvasNodePosition(ingressBounds(ingress), canvasCollisionNodes(applications, ingresses), CANVAS_CARD_GAP);

    return { ...ingress, canvasX: position.x, canvasY: position.y };
}
