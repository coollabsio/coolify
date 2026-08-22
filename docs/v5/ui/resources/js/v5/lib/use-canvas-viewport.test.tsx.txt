// @vitest-environment jsdom
import { act, renderHook } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import type { PointerEvent, WheelEvent } from 'react';

import { APPLICATION_CARD_HEIGHT, APPLICATION_CARD_WIDTH } from '@/lib/canvas-geometry';
import { MAX_CANVAS_ZOOM, MIN_CANVAS_ZOOM, PINCH_CANVAS_ZOOM_STEP, useCanvasViewport } from '@/lib/use-canvas-viewport';

const CANVAS_WIDTH = 800;
const CANVAS_HEIGHT = 600;

function canvasElement(): HTMLDivElement {
    const element = document.createElement('div');

    element.getBoundingClientRect = () =>
        ({ left: 0, top: 0, width: CANVAS_WIDTH, height: CANVAS_HEIGHT, right: CANVAS_WIDTH, bottom: CANVAS_HEIGHT, x: 0, y: 0 }) as DOMRect;

    return element;
}

function renderViewport() {
    const rendered = renderHook(() => useCanvasViewport());

    rendered.result.current.canvasRef.current = canvasElement();

    return rendered;
}

describe('zoomCanvas', () => {
    it('zooms in by one step around the canvas center', () => {
        const { result } = renderViewport();

        act(() => result.current.zoomCanvas(1));

        expect(result.current.viewport.zoom).toBeCloseTo(1.1);
        // The canvas point under the viewport center must stay put.
        expect((CANVAS_WIDTH / 2 - result.current.viewport.x) / result.current.viewport.zoom).toBeCloseTo(CANVAS_WIDTH / 2);
        expect((CANVAS_HEIGHT / 2 - result.current.viewport.y) / result.current.viewport.zoom).toBeCloseTo(CANVAS_HEIGHT / 2);
    });

    it('keeps an explicit zoom origin stable', () => {
        const { result } = renderViewport();
        const origin = { x: 100, y: 50 };

        act(() => result.current.zoomCanvas(1, 0.5, origin));

        const { x, y, zoom } = result.current.viewport;

        expect(zoom).toBeCloseTo(1.5);
        expect((origin.x - x) / zoom).toBeCloseTo(origin.x);
        expect((origin.y - y) / zoom).toBeCloseTo(origin.y);
    });

    it('clamps zoom to the maximum', () => {
        const { result } = renderViewport();

        act(() => result.current.zoomCanvas(1, 10));

        expect(result.current.viewport.zoom).toBe(MAX_CANVAS_ZOOM);
    });

    it('clamps zoom to the minimum', () => {
        const { result } = renderViewport();

        act(() => result.current.zoomCanvas(-1, 10));

        expect(result.current.viewport.zoom).toBe(MIN_CANVAS_ZOOM);
    });

    it('does nothing when the canvas element is not mounted', () => {
        const { result } = renderHook(() => useCanvasViewport());

        act(() => result.current.zoomCanvas(1));

        expect(result.current.viewport).toEqual({ x: 0, y: 0, zoom: 1 });
    });
});

describe('handleCanvasWheel', () => {
    function wheelEvent(overrides: Partial<{ ctrlKey: boolean; deltaY: number; clientX: number; clientY: number }>) {
        return {
            ctrlKey: true,
            deltaY: -1,
            clientX: 0,
            clientY: 0,
            currentTarget: canvasElement(),
            preventDefault: () => {},
            ...overrides,
        } as unknown as WheelEvent<HTMLDivElement>;
    }

    it('pinch-zooms in on ctrl+wheel up', () => {
        const { result } = renderViewport();

        act(() => result.current.handleCanvasWheel(wheelEvent({ deltaY: -1 })));

        expect(result.current.viewport.zoom).toBeCloseTo(1 + PINCH_CANVAS_ZOOM_STEP);
    });

    it('pinch-zooms out on ctrl+wheel down', () => {
        const { result } = renderViewport();

        act(() => result.current.handleCanvasWheel(wheelEvent({ deltaY: 1 })));

        expect(result.current.viewport.zoom).toBeCloseTo(1 - PINCH_CANVAS_ZOOM_STEP);
    });

    it('ignores plain scrolling without ctrl', () => {
        const { result } = renderViewport();

        act(() => result.current.handleCanvasWheel(wheelEvent({ ctrlKey: false })));

        expect(result.current.viewport.zoom).toBe(1);
    });
});

describe('centerOnCanvasNodes', () => {
    it('centers a single card in the canvas', () => {
        const { result } = renderViewport();

        act(() => result.current.centerOnCanvasNodes([{ canvasX: 0, canvasY: 0 }], []));

        expect(result.current.viewport).toEqual({
            x: CANVAS_WIDTH / 2 - APPLICATION_CARD_WIDTH / 2,
            y: CANVAS_HEIGHT / 2 - APPLICATION_CARD_HEIGHT / 2,
            zoom: 1,
        });
    });

    it('centers on the midpoint of multiple nodes', () => {
        const { result } = renderViewport();

        act(() => result.current.centerOnCanvasNodes([{ canvasX: 0, canvasY: 0 }], [{ canvasX: 400, canvasY: 200 }]));

        expect(result.current.viewport).toEqual({
            x: CANVAS_WIDTH / 2 - (200 + APPLICATION_CARD_WIDTH / 2),
            y: CANVAS_HEIGHT / 2 - (100 + APPLICATION_CARD_HEIGHT / 2),
            zoom: 1,
        });
    });

    it('resets the pan but keeps the zoom when there are no nodes', () => {
        const { result } = renderViewport();

        act(() => result.current.zoomCanvas(1));
        act(() => result.current.centerOnCanvasNodes([], []));

        expect(result.current.viewport).toEqual({ x: 0, y: 0, zoom: result.current.viewport.zoom });
        expect(result.current.viewport.x).toBe(0);
        expect(result.current.viewport.y).toBe(0);
    });
});

describe('canvasPointFromPointer', () => {
    function pointerEvent(clientX: number, clientY: number): PointerEvent {
        return { clientX, clientY } as PointerEvent;
    }

    it('maps client coordinates through pan and zoom', () => {
        const { result } = renderViewport();

        act(() => result.current.setViewport({ x: 100, y: 50, zoom: 2 }));

        expect(result.current.canvasPointFromPointer(pointerEvent(300, 250))).toEqual({ x: 100, y: 100 });
    });

    it('returns the origin when the canvas element is not mounted', () => {
        const { result } = renderHook(() => useCanvasViewport());

        expect(result.current.canvasPointFromPointer(pointerEvent(300, 250))).toEqual({ x: 0, y: 0 });
    });
});
