import test from 'node:test';
import assert from 'node:assert/strict';
import { panZoom } from './canvas-core.js';

const rect = () => ({ left: 0, top: 0, width: 800, height: 600 });
const fakeElement = () => Object.assign(new EventTarget(), {
  style: { setProperty() {} },
  classList: { add() {}, remove() {}, toggle() {} },
  getBoundingClientRect: rect,
  setPointerCapture() {},
});

/** A viewport whose presses never start a left-button pan (they land on cards). */
function canvas() {
  const viewport = fakeElement();
  const view = { x: 0, y: 0, z: 1 };
  const ends = [];
  panZoom({ root: fakeElement(), viewport, world: { style: {} }, zoomLabel: {}, getView: () => view, canPan: () => false, onPanEnd: moved => ends.push(moved) });
  const fire = (type, props = {}) => {
    const event = Object.assign(new Event(type, { cancelable: true }), { pointerId: 1, pointerType: 'mouse', button: 0, clientX: 0, clientY: 0 }, props);
    viewport.dispatchEvent(event);
    return event;
  };
  return { view, ends, fire };
}

test('the middle mouse button pans from a card, and the release does not open a link', () => {
  const { view, ends, fire } = canvas();
  assert.equal(fire('pointerdown', { button: 1, clientX: 10, clientY: 10 }).defaultPrevented, true, 'no autoscroll');
  fire('pointermove', { button: 1, clientX: 60, clientY: 40 });
  fire('pointerup', { button: 1, clientX: 60, clientY: 40 });

  assert.deepEqual({ x: view.x, y: view.y }, { x: 50, y: 30 });
  assert.deepEqual(ends, [], 'a middle pan does not close the side panel');
  assert.equal(fire('auxclick', { button: 1 }).defaultPrevented, true);
});

test('a middle click without a move still opens the link under the pointer', () => {
  const { fire } = canvas();
  fire('pointerdown', { button: 1, clientX: 10, clientY: 10 });
  fire('pointerup', { button: 1, clientX: 10, clientY: 10 });

  assert.equal(fire('auxclick', { button: 1 }).defaultPrevented, false);
});

test('a left-button press on a card does not pan', () => {
  const { view, fire } = canvas();
  fire('pointerdown', { clientX: 10, clientY: 10 });
  fire('pointermove', { clientX: 60, clientY: 40 });
  fire('pointerup', { clientX: 60, clientY: 40 });

  assert.deepEqual({ x: view.x, y: view.y }, { x: 0, y: 0 });
});

test('a native drag (card logo, link) cannot start and cancel the card drag', () => {
  const { fire } = canvas();

  assert.equal(fire('dragstart').defaultPrevented, true);
});
