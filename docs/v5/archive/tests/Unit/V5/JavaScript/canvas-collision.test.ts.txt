import assert from 'node:assert/strict';
import { test } from 'node:test';

import { resolveCanvasNodeLayout, resolveCanvasNodePosition } from '../../../../resources/js/v5/lib/canvas-collision.ts';

test('moves a dragged canvas node to the closest non-overlapping side with a gap', () => {
    const settledPosition = resolveCanvasNodePosition(
        { id: 'dragged', x: 110, y: 10, width: 320, height: 136 },
        [{ id: 'existing', x: 0, y: 0, width: 320, height: 136 }],
        16,
    );

    assert.deepEqual(settledPosition, { x: 110, y: 152 });
});

test('keeps moving until the closest side is clear', () => {
    const settledPosition = resolveCanvasNodePosition(
        { id: 'dragged', x: 110, y: 10, width: 320, height: 136 },
        [
            { id: 'left-blocker', x: 0, y: 0, width: 320, height: 136 },
            { id: 'bottom-blocker', x: 110, y: 152, width: 320, height: 136 },
            { id: 'top-blocker', x: 110, y: -152, width: 320, height: 136 },
        ],
        16,
    );

    assert.deepEqual(settledPosition, { x: -336, y: 10 });
});

test('ignores the dragged node when comparing canvas collisions', () => {
    const settledPosition = resolveCanvasNodePosition(
        { id: 'app-1', x: 24, y: 32, width: 320, height: 136 },
        [{ id: 'app-1', x: 24, y: 32, width: 320, height: 136 }],
        16,
    );

    assert.deepEqual(settledPosition, { x: 24, y: 32 });
});


test('spreads an overlapping canvas layout in order', () => {
    const settledNodes = resolveCanvasNodeLayout(
        [
            { id: 'first', x: 0, y: 0, width: 320, height: 136 },
            { id: 'second', x: 110, y: 10, width: 320, height: 136 },
        ],
        16,
    );

    assert.deepEqual(
        settledNodes.map((node) => ({ id: node.id, x: node.x, y: node.y })),
        [
            { id: 'first', x: 0, y: 0 },
            { id: 'second', x: 110, y: 152 },
        ],
    );
});
