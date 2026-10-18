import test from 'node:test';
import assert from 'node:assert/strict';
import { parseSubmitAction } from './modal-confirmation.js';

test('keeps unquoted arguments as text, as the modal always passed them', () => {
    assert.deepEqual(parseSubmitAction('delete(5)'), { method: 'delete', params: ['5'] });
    assert.deepEqual(parseSubmitAction('changeSource(3, App\\Models\\GithubApp)'), {
        method: 'changeSource',
        params: ['3', 'App\\Models\\GithubApp'],
    });
    assert.deepEqual(parseSubmitAction('resetDefaultLabels(true)'), { method: 'resetDefaultLabels', params: ['true'] });
});

test('removes the quotes of quoted arguments', () => {
    assert.deepEqual(parseSubmitAction("generateNginxConfiguration('spa')"), {
        method: 'generateNginxConfiguration',
        params: ['spa'],
    });
    assert.deepEqual(parseSubmitAction("replaceManagedDnsRecord('app.example.com', 4)"), {
        method: 'replaceManagedDnsRecord',
        params: ['app.example.com', '4'],
    });
    assert.deepEqual(parseSubmitAction('rename("staging")'), { method: 'rename', params: ['staging'] });
});

test('keeps commas, parentheses and escaped quotes inside quoted arguments', () => {
    assert.deepEqual(parseSubmitAction("save('a, b', 'c)d', 'it\\'s')"), {
        method: 'save',
        params: ['a, b', 'c)d', "it's"],
    });
});

test('passes no arguments for empty parentheses or a bare method name', () => {
    assert.deepEqual(parseSubmitAction('cleanupDeleted()'), { method: 'cleanupDeleted', params: [] });
    assert.deepEqual(parseSubmitAction('delete'), { method: 'delete', params: [] });
});
