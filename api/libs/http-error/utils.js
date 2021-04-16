/* eslint-disable */
"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.get = exports.upperFirst = void 0;
function upperFirst(source) {
    if (typeof source !== 'string' || !source.length) {
        return source;
    }
    return source[0].toUpperCase() + source.substring(1);
}
exports.upperFirst = upperFirst;
function get(target, path) {
    var _a;
    const tokens = path.split('.').map((t) => t.trim());
    for (const token of tokens) {
        if (typeof target === 'undefined' || target === null) {
            // We're supposed to be still iterating, but the chain is over - Return undefined
            target = undefined;
            break;
        }
        const index = token.match(/^(\d+)|(?:\[(\d+)\])$/);
        if (index) {
            target = target[parseInt((_a = index[1]) !== null && _a !== void 0 ? _a : index[2], 10)];
        }
        else {
            target = target[token];
        }
    }
    return target;
}
exports.get = get;
