import test from 'node:test';
import assert from 'node:assert/strict';
import { moveStrayNodesBehindHead, registerNavigateSnapshotGuard } from './navigate-snapshot-guard.js';

class FakeElement {
    constructor(tagName) {
        this.tagName = tagName;
        this.children = [];
    }

    append(...children) {
        this.children.push(...children);
    }

    insertBefore(child, reference) {
        const current = this.children.indexOf(child);

        if (current > -1) {
            this.children.splice(current, 1);
        }

        this.children.splice(this.children.indexOf(reference), 0, child);
    }
}

function createDocument(tagNamesBeforeHead = []) {
    const documentElement = new FakeElement('HTML');
    const head = new FakeElement('HEAD');
    const body = new FakeElement('BODY');

    documentElement.append(...tagNamesBeforeHead.map((tagName) => new FakeElement(tagName)), head, body);

    return { documentElement, head, body };
}

const tagNames = (document) => document.documentElement.children.map((child) => child.tagName);

test('an extension element injected before the head is moved behind it', () => {
    const document = createDocument(['PLASMO-CSUI']);

    assert.equal(moveStrayNodesBehindHead(document), 1);
    assert.deepEqual(tagNames(document), ['HEAD', 'PLASMO-CSUI', 'BODY']);
});

test('every stray keeps its relative order', () => {
    const document = createDocument(['FIRST-OVERLAY', 'SECOND-OVERLAY']);

    assert.equal(moveStrayNodesBehindHead(document), 2);
    assert.deepEqual(tagNames(document), ['HEAD', 'FIRST-OVERLAY', 'SECOND-OVERLAY', 'BODY']);
});

test('an untouched document is left alone', () => {
    const document = createDocument();

    assert.equal(moveStrayNodesBehindHead(document), 0);
    assert.deepEqual(tagNames(document), ['HEAD', 'BODY']);
});

test('elements already sitting between the head and the body are not moved', () => {
    const document = createDocument();
    document.documentElement.insertBefore(new FakeElement('PLASMO-CSUI'), document.body);

    assert.equal(moveStrayNodesBehindHead(document), 0);
    assert.deepEqual(tagNames(document), ['HEAD', 'PLASMO-CSUI', 'BODY']);
});

test('the guard runs when Livewire is about to snapshot the page', () => {
    const document = createDocument(['PLASMO-CSUI']);
    const listeners = {};
    document.addEventListener = (name, callback) => listeners[name] = callback;

    registerNavigateSnapshotGuard(document);
    listeners['livewire:navigating']();

    assert.deepEqual(tagNames(document), ['HEAD', 'PLASMO-CSUI', 'BODY']);
});
