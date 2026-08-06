import { describe, expect, it } from 'vitest';
import { resolveCanvasNodeLayout, resolveCanvasNodePosition, type CanvasNodeBounds } from '@/lib/canvas-collision';

const GAP = 16;

function node(id: string, x: number, y: number, width = 320, height = 160): CanvasNodeBounds {
    return { id, x, y, width, height };
}

function nodesOverlap(first: CanvasNodeBounds, second: CanvasNodeBounds, gap: number): boolean {
    return (
        first.x < second.x + second.width + gap &&
        first.x + first.width + gap > second.x &&
        first.y < second.y + second.height + gap &&
        first.y + first.height + gap > second.y
    );
}

describe('resolveCanvasNodePosition', () => {
    it('keeps the position when there is no collision', () => {
        const position = resolveCanvasNodePosition(node('a', 0, 0), [node('b', 1000, 1000)], GAP);

        expect(position).toEqual({ x: 0, y: 0 });
    });

    it('ignores the node itself when checking collisions', () => {
        const subject = node('a', 0, 0);

        const position = resolveCanvasNodePosition(subject, [subject], GAP);

        expect(position).toEqual({ x: 0, y: 0 });
    });

    it('moves an overlapping node to a gap-respecting position', () => {
        const settled = node('a', 0, 0);

        const position = resolveCanvasNodePosition(node('b', 10, 10), [settled], GAP);

        expect(nodesOverlap({ ...node('b', 10, 10), ...position }, settled, GAP)).toBe(false);
    });

    it('picks the candidate closest to the requested position', () => {
        const settled = node('a', 0, 0);

        // Dropped near the settled node's top-left corner: the slot above is nearest.
        const position = resolveCanvasNodePosition(node('b', 40, 0), [settled], GAP);

        expect(position).toEqual({ x: 40, y: -(160 + GAP) });
    });
});

describe('resolveCanvasNodeLayout', () => {
    it('settles all nodes without overlaps', () => {
        const settled = resolveCanvasNodeLayout(
            [node('a', 0, 0), node('b', 0, 0), node('c', 0, 0), node('d', 0, 0)],
            GAP,
        );

        expect(settled).toHaveLength(4);

        for (const first of settled) {
            for (const second of settled) {
                if (first.id !== second.id) {
                    expect(nodesOverlap(first, second, GAP)).toBe(false);
                }
            }
        }
    });

    it('preserves node order and ids', () => {
        const settled = resolveCanvasNodeLayout([node('a', 0, 0), node('b', 0, 0)], GAP);

        expect(settled.map((settledNode) => settledNode.id)).toEqual(['a', 'b']);
    });
});
