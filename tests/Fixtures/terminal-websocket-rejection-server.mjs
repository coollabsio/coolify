// Minimal stand-in for the terminal WebSocket server used by browser tests.
// Built on Node core only (no `ws`), it reproduces how the real server rejects
// authentication so the browser client can be tested end to end.
//
// Usage: node terminal-websocket-rejection-server.mjs <mode>
//   close-on-upgrade: complete the handshake, then close with 4401 (session rejected)
//   reject-token:     on the first client frame, send the legacy text message and close with 4403
//
// Prints the listening port on stdout. GET /stats returns {"upgrades": n}.
import { createHash } from 'node:crypto';
import http from 'node:http';

const mode = process.argv[2] ?? 'close-on-upgrade';
let upgrades = 0;

function frame(opcode, payload) {
    if (payload.length > 125) {
        throw new Error('Fixture only supports short frames.');
    }

    return Buffer.concat([Buffer.from([0x80 | opcode, payload.length]), payload]);
}

function closeFrame(code, reason) {
    const payload = Buffer.alloc(2 + Buffer.byteLength(reason));
    payload.writeUInt16BE(code, 0);
    payload.write(reason, 2);

    return frame(0x8, payload);
}

const server = http.createServer((req, res) => {
    res.writeHead(200, { 'Content-Type': 'application/json', 'Access-Control-Allow-Origin': '*' });
    res.end(JSON.stringify({ upgrades }));
});

server.on('upgrade', (req, socket) => {
    upgrades++;
    socket.on('error', () => socket.destroy());

    const accept = createHash('sha1')
        .update(`${req.headers['sec-websocket-key']}258EAFA5-E914-47DA-95CA-C5AB0DC85B11`)
        .digest('base64');

    socket.write([
        'HTTP/1.1 101 Switching Protocols',
        'Upgrade: websocket',
        'Connection: Upgrade',
        `Sec-WebSocket-Accept: ${accept}`,
        '',
        '',
    ].join('\r\n'));

    if (mode === 'close-on-upgrade') {
        socket.end(closeFrame(4401, 'Unauthorized: Invalid credentials'));
        return;
    }

    socket.once('data', () => {
        const message = 'Unauthorized: Terminal token was rejected';
        socket.write(frame(0x1, Buffer.from(message)));
        socket.end(closeFrame(4403, message));
    });
});

server.listen(0, '127.0.0.1', () => {
    process.stdout.write(`${server.address().port}\n`);
});
