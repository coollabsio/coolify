import { useCallback, useRef, useState, type PointerEvent, type WheelEvent } from 'react';

import { APPLICATION_CARD_HEIGHT, APPLICATION_CARD_WIDTH, type CanvasCardNode } from '@/lib/canvas-geometry';

export type Viewport = {
    x: number;
    y: number;
    zoom: number;
};

export const MIN_CANVAS_ZOOM = 0.5;
export const MAX_CANVAS_ZOOM = 2;
export const CANVAS_ZOOM_STEP = 0.1;
export const PINCH_CANVAS_ZOOM_STEP = 0.03;

function clampCanvasZoom(zoom: number): number {
    return Math.min(MAX_CANVAS_ZOOM, Math.max(MIN_CANVAS_ZOOM, zoom));
}

export function useCanvasViewport() {
    const canvasRef = useRef<HTMLDivElement | null>(null);
    const [viewport, setViewport] = useState<Viewport>({ x: 0, y: 0, zoom: 1 });
    const viewportRef = useRef(viewport);

    viewportRef.current = viewport;

    const zoomCanvas = useCallback((direction: 1 | -1, step = CANVAS_ZOOM_STEP, origin?: { x: number; y: number }): void => {
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
    }, []);

    const handleCanvasWheel = useCallback(
        (event: WheelEvent<HTMLDivElement>): void => {
            if (!event.ctrlKey) {
                return;
            }

            const rect = event.currentTarget.getBoundingClientRect();

            event.preventDefault();
            zoomCanvas(event.deltaY < 0 ? 1 : -1, PINCH_CANVAS_ZOOM_STEP, {
                x: event.clientX - rect.left,
                y: event.clientY - rect.top,
            });
        },
        [zoomCanvas],
    );

    const centerOnCanvasNodes = useCallback((applications: CanvasCardNode[], ingresses: CanvasCardNode[]): void => {
        const canvas = canvasRef.current;
        const nodes = [...applications, ...ingresses];

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
    }, []);

    const canvasPointFromPointer = useCallback((event: PointerEvent): { x: number; y: number } => {
        const rect = canvasRef.current?.getBoundingClientRect();
        const currentViewport = viewportRef.current;

        if (!rect) {
            return { x: 0, y: 0 };
        }

        return {
            x: (event.clientX - rect.left - currentViewport.x) / currentViewport.zoom,
            y: (event.clientY - rect.top - currentViewport.y) / currentViewport.zoom,
        };
    }, []);

    return {
        canvasRef,
        viewport,
        setViewport,
        zoomCanvas,
        handleCanvasWheel,
        centerOnCanvasNodes,
        canvasPointFromPointer,
    };
}
